<?php

/**
*	NOTICE: To use the async function, you must have php compiled with mysqlnd (default in php 5.3+)
*	@abstract This class is a for mysqli that provides a straightforward interface to the better aspects of
*		the mysqli extension including asynchronous queries
*	@author Rob McVey
*
*/
class sqlnd extends mysqli
{
	/**
	*	@property history [collection object] - container for stored (non-async) query results
	*/
	private $history = NULL;
	
	/**
	*	@property stmt [object] - holds the mysqli_stmt object
	*/
	private $stmt 	= NULL;
	
	/**
	*	@property is_async [bool] - flag for whether an asynchronous operation is taking place
	*/
	private $is_async = false;
	
	/**
	*	@property result [array] - container for query result(s)
	*/
	private $result = NULL;
	
	/**
	*	@property errors [array] - container for query/connection error(s)
	*/
	private $errors = NULL;
	
	/**
	*	@property links [array] - container for asynchronous links
	*/
	private $links 	= NULL;
	
	public function __construct($host, $user, $pass, $db)
	{
		parent::__construct($host, $user, $pass, $db);
		$this->history = new collection("container");
	}
	
	public function __destruct()
	{
		$this->close();
	}
	
	/**
	*	Asynchronously executes passed sql
	*	@access public
	*	@param sql [string] SQL statement
	*	@return this [object] self reference to support method chaining
	*/
	public function async($sql)
	{
		$this->is_async = true;
		if(is_array($sql))
		{
			foreach($sql as $sql_key => $query)
			{
				$async_collection = new collection($sql_key);
				$this->query($query, MYSQLI_ASYNC);
				$resource = $this->reap_async_query();
				if(is_object($resource))
				{
					$async_collection->push($resource);
					$this->history->push($async_collection);
					$this->links = array($this);
				}
				else
				{
					$async_collection->push(NULL);
					$this->history->push($async_collection);
					$this->links = NULL;
				}
				
			}
		}
		else
		{
			$async_collection = new collection($sql_key);
			$resource = $this->query($query, MYSQLI_ASYNC);
			$async_collection->push($resource);
		}
		return $this;
	}
	
	/**
	*	Synchronously executes passed sql
	*	@access public
	*	@param sql [string] SQL statement
	*	@return this [object] self reference to support method chaining
	*/
	public function execute($sql)
	{
		if(substr(strtoupper($sql),0,8) == "INSERT")
		{
			return $this->async($sql);
		}
		return $this->_fill($this->query($sql));
	}
	
	private function _fill(&$res)
	{
		$raw = $res;
		$this->result = NULL;
		while($row = $raw->fetch_object())
		{
			$this->result[]=$row;
		}
		
		return $this;
	}
	
	/**
	*	Performs a multi query statement
	*	@access public
	*	@param sql [string|array] String with multiple queries separated by semi-colon or array of queries (no semicolon)
	*	@return this [object] self reference to support method chaining
	*/
	public function multi($sql)
	{
		$this->_reset();
		
		if(is_array($sql))
		{
			$sql = implode(";", $sql);
		} 

		$this->multi_query($sql);
		$count = 0;

		do 
		{
	        if ($result = $this->store_result()) 
			{
	            while ($row = $result->fetch_object()) 
				{
	                $this->result[$count] []= $row;
	            }
	            $result->free();
	        }

	        if ($this->more_results()) 
			{
	            $count++;
	        }
	    } while ($this->next_result());
		
		return $this;
	}
	
	/**
	*	Prepares a SQL statement
	*	@access public
	*	@param sql [string] String with SQL query
	*	@param params [array] Indexed array of items to replace in query
	*	@return this [object] self reference to support method chaining
	*/
	public function bind($sql, $params=NULL)
	{
		if(!$params && !strstr($sql, "?"))
		{
			return $this->execute($sql);
		} 
		if($this->stmt)
		{
			unset($this->stmt);
		}
		$this->stmt = new stmt($sql, $params, $this);
		
		return $this;
	}
	
	/**
	*	Retrieves the data produced by query/queries
	*	@access public
	*	@return [array|integer] - cases:
	*		- MULTI-QUERY: multi-dimensional array, one outer array per query result
	*		- PREPARED/ASYNC/EXECUTE - single dimension array of results
	*		- INSERT operation: insert_id
	*/
	public function data($name=NULL)
	{
		if(!is_null($this->stmt))
		{
			$this->_stmt_data();
		} 
		else if($this->is_async)
		{
			$this->_async_data();
		}
		
		$historical_entry = new collection($name);
		$historical_entry->push($this->result);
		$this->history->push($historical_entry);
		
		$retval = $this->result;
		
		$this->reset;
		
		return $retval;
	}
	
	public function history($hash=NULL, $file=__FILE__)
	{
		if(!is_null($hash))
		{
			if(is_numeric($hash))
			{
				return $this->history->seek($hash);
			} 
			else if(!empty($hash))
			{
				return $this->history->get_queue($hash);
			}
		}
		return false;
	}
	
	private function _reset()
	{
		if($this->is_async)
		{
			/**
			*	@todo this just throws the data away without any work (it doesn't save it in history)
			*	cache mysqli_result object for thrown away data
			*/
			$async_object = new collection("");
			$this->history->push($this->reap_async_query());
		}
		$this->stmt	 	= NULL;
		$this->is_async = false;
		$this->result 	= NULL;
	}
	
	private function _async_data()
	{
		$links = $errors = $reject = array();
		
		do
		{
			foreach ($this->links as $link) 
			{
		        $links[] = $errors[] = $reject[] = $link;
		    }
		} while (!$this->poll($links, $errors, $reject, 0, 1));
		
		foreach($links as $data)
		{
			if($result = $data->reap_async_query())
			{
				if(is_object($result))
				{
					$this->result = $result->fetch_all();
					$result->free();
				}
				else
				{
					$this->result = $data->insert_id;
				}
			}
		}

		$this->is_async = false;
	}
	
	private function _stmt_data()
	{
		$this->result = $this->stmt->execute();
		$this->stmt = NULL;
	}
}

class collection
{
	private $name;
	private $queue = array();
	
	public function __construct($name=NULL)
	{
		if(is_null($name))
		{
			$bt 	= debug_backtrace();
			$file 	= explode(".", str_replace("/", "", $bt[0]['file']) );
			$name 	= $file[0].$bt[0]['line'];
		}
		$this->name = $name;
	}
	
	public function get_queue($name=NULL)
	{
		if(array_key_exists($name, $this->queue))
		{
			return $this->queue[$name]->queue;
		}
		else
		{
			return false;
		}
	}
	
	public function get_queue_like($name=NULL)
	{
		$keys 		= array_keys($this->queue);
		$shortest 	= -1;
		foreach($keys as $key)
		{
			$lev = levenshtein($key, $name);
			if($lev <= $shortest || $shortest < 0)
			{
				$closest 	= $key;
				$shortest 	= $lev;
				$hash		= $key;
			}
		}
		return $this->queue[$hash];
	}

	public function seek($num)
	{
		if($num < 0)
		{
			$num = abs($num);
			$num = ( count($this->queue) - $num );
		}
		return $this->queue[$num];
	}
	
	public function get_name()
	{
		return $this->name;
	}
	
	public function push($item) 
	{ 
		if(is_object($item) && get_class($item) == "collection")
		{
			return $this->queue[$item->name] = $item;
		}
		return array_push($this->queue, $item); 
	}
	
	public function pop() 
	{ 
		return array_shift($this->queue); 
	}
	
	public function display($arr=null)
	{
		if (!is_null($arr))
		{
			$temp = $arr;
		}
		else
		{
			$temp = $this->queue;
		}

		foreach ($temp as $item)
		{
			if (is_array($item))
			{
				$this->display($item);
			}
			else
			{
				echo $item . " ";
			}
		}
	}
}

/**
*	@abstract This class is a wrapper for mysqli_stmt that provides a more straightforward interface to the better aspects of
*		the mysqli_stmt class including prepared statements and result binding via arrays, rather than individual variables
*/
class stmt
{
	private $raw;
	private $stmt;
	private $params;
	private $data;
	private $host;
	
	public function __construct($sql, $params, &$host)
	{
		$this->raw 		= $sql;
		$this->host		= $host;
		$this->stmt		= $this->host->prepare($this->raw);
		$this->params 	= $params;
		$this->bind();
	}
	
	private function bind()
	{
		if($this->stmt)
		{
			$format = str_repeat("s", count($this->params));
		
			for($i = 0; $i < count($this->params); $i++)
			{
				if(is_numeric($this->params[$i]))
				{
					$format = substr_replace($format, "i", $i, 1);
				}
			}
		
			$this->params = array_merge(array($format), $this->params);
			
			for($i = 0; $i < count($this->params); $i++)
			{
				$this->params[$i] = &$this->params[$i];
			}
		
			call_user_func_array(array($this->stmt, "bind_param"), $this->params);
		}
	}
	
	private function _stmt_bind_assoc(&$out) 
	{
	    $data 	= $this->stmt->result_metadata();
	    $fields = array();
	    $out 	= array();
	    $count = 0;

	    while($field = $data->fetch_field()) 
		{
	        $fields[$count] = &$out[$field->name];
	        $count++;
	    }
	    call_user_func_array(array($this->stmt, "bind_result"), $fields);
	}
	
	public function execute()
	{
		$this->stmt->execute();
		$result = array();
		$row 	= array();
		
		$this->_stmt_bind_assoc($row);

		while ($this->stmt->fetch()) 
		{
		    foreach($row as $key => $value)
		    {
		        $row_tmb[ $key ] = $value;
		    }
		    $result[] = $row_tmb;
		}
		$this->stmt->free_result();
		return $result;
	}
}

?>