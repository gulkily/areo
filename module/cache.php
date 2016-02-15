<?php

function get_cache_filename($cache_name) {
    //review complete

    return CACHE_PATH . (substr($cache_name,0,1)=='/'?'':'/') . $cache_name;
}

function get_cache_list($path = '', $readable = 0) {
    // review pending
    // is this function really necessary?

    $path = get_cache_filename($path);

    $caches_glob = glob($path.'*', GLOB_MARK);

    $caches = array();

    foreach($caches_glob as $cache) {
        if ($readable) {
            $caches[] = array (
                'filename' => substr($cache, strlen(CACHE_PATH)),
                'filesize' => (is_dir($cache)?'--':number_format(filesize($cache), 0, '.', ',')),
                'mtime' => date('Y-m-d H:i:s', filemtime($cache)),
                'atime' => date('Y-m-d H:i:s', fileatime($cache)),
            );
        } else {
            $caches[] = array (
                'filename' => substr($cache, strlen(CACHE_PATH)),
                'filesize' => (is_dir($cache)?null:filesize($cache)),
                'mtime' => filemtime($cache),
                'atime' => fileatime($cache),
            );
        }
    }

    return $caches;
}

function put_cache($cache_name, $object, $nest_level = 0) {
    //review pending

    if ($nest_level > 10) {
        trigger_error('put_cache() went more than 10 levels deep.', E_USER_ERROR);
    }

    $filename = get_cache_filename($cache_name);
    $tmp = getmypid().'.tmp';

    $object_s = serialize($object);
    $file = @fopen($filename.$tmp, 'w');

    if (!$file) {
    // if we don't have a handle, we probably need to create some directories
        $path = $filename;
        while (!$file && $path != '') {
            $path = explode('/', $path);
            array_pop($path);
            $path = implode('/', $path);

            if (file_exists($path)) {
                unlink($path);
            }

            mkdir($path, 0777);
            $file = @fopen($filename.$tmp, 'w');
        }

        if (!$file) {
        // now that we have a directory and a file handle, try again
            put_cache($cache_name, $object, $nest_level+1);
        }
    }

    if ($file) {
        fwrite($file, $object_s);
        fclose($file);
        rename($filename.$tmp, $filename);
    }
}

function get_cache_timestamp($cache_name) {
    //review complete

    $filename = get_cache_filename($cache_name);

    if (file_exists($filename)) {
        $cache_timestamp = filemtime($filename);

        return $cache_timestamp;
    } else {
        return null;
    }

}

function cache_expired($cache_name, $threshold = 60) {
    //review complete

    // check if cache has expired and needs to be renewed
    $filename = get_cache_filename($cache_name);

    if (file_exists($filename)) {
        $cache_timestamp = filemtime($filename);

        $time_diff = time() - $cache_timestamp;
        if ($time_diff < $threshold)
            return 0;
        else
            return $time_diff - $threshold;
    } else {
        return -1;
    }
}

function store_cache_queue() {
    //review complete

    // this will call the queue_cache() function with $write = 1 to write all the caches to queue to the database
    queue_cache('', '', '', 1);
}

function queue_cache($cache_name, $query, $params = array(), $write = 0) {
    //review complete, needs testing

    // if $write == 0, adds the query to the static array of queries to add to the cache queue
    // if $write == 1, flushes the array to the cache queue in the database

    static $caches;

    if ($write) {
        global $db;

        if (count($caches) > 0) {
            foreach ($caches as $cache) {
                $stmt = $db->prepare("
                    INSERT INTO cache_queue(cache_name, query, params, add_timestamp)
                    VALUES (:cache_name, :query, :params, NOW())
                ");

                $stmt->execute($cache);
            }
        }
        $caches = array();
    } else {
        $params = json_encode($params);

        $caches[] = array(
            ':cache_name' => $cache_name,
            ':query' => $query,
            ':params' => $params
        );
    }
}

/** @noinspection PhpInconsistentReturnPointsInspection */
function get_cache($cache_name, $query = '', $query_params = array(), $refresh_rate = 60, $force_refresh = 0) {
    //review complete, needs testing

    if ($cache_name) {
        $filename = get_cache_filename($cache_name);
    } else {
        $filename = get_cache_filename(md5($query));
    }

    $expired = cache_expired($cache_name, $refresh_rate);

    if (!$force_refresh && cache_expired($cache_name, $refresh_rate) > 0 && $query) {
        queue_cache($cache_name, $query, $query_params);
    }

    if (($force_refresh && $expired || $expired < 0)  && $cache_name != '' && $query) {
        global $db;

        $stmt = $db->prepare($query);
        $stmt->execute($query_params);

        $results = $stmt->fetchAll();

        if (!mysql_error()) {
            put_cache($cache_name, $results);

            return $results;
        } elseif ($expired > 0) {
            return get_cache($cache_name, $query, $query_params, $refresh_rate, 0);
        }
    } elseif (file_exists($filename)) {
        $file_length = filesize($filename);
        $file = fopen($filename, 'r');

        if ($file_length > 0) {
            $results_array = fread($file, $file_length);
            fclose($file);
            return unserialize($results_array);
        } else {
            return array();
        }
    }
}

function expire_cache($cache_name, $delete = 0) {
    $filename = get_cache_filename($cache_name);
    $files = glob($filename);

    if (count($files)) {
        foreach($files as $filename) {
            if (file_exists($filename)) {
                if ($delete) {
                    unlink($filename);
                } else {
                    touch($filename, time()-31536000);
                }
            }
        }
    }
}

function batch_cache($count = 10, $max_runtime = 5) {
    //review complete, needs testing

    $count = intval($count);
    if (!$count) {
        trigger_error('batch_cache() $count is required', E_USER_ERROR);
    }

    $max_runtime = intval($max_runtime);
    if (!$max_runtime) {
        trigger_error('batch_cache() $max_runtime is required', E_USER_ERROR);
    }

    global $db;
    $start_time = microtime(true);

    $query = "
        SELECT
            cache_name AS cache_name,
            MIN(add_timestamp) AS first_req,
            COUNT(cache_name) AS req_count,
            params AS params,
            MAX(query) AS query
        FROM cache_queue
        GROUP BY cache_name, params
        ORDER BY req_count DESC, first_req
        LIMIT $count
    ";

    $stmtCaches = $db->prepare($query);
    $stmtCaches->execute();

    $caches = $stmtCaches->fetchAll();

    if (count($caches)) {
        $cached = array();
        $not_cached = array();

        foreach($caches as $cache) {
            if (microtime() - $start_time >= $max_runtime) {
                $not_cached[] = $cache->cache_name;
            } else {
                $params = json_decode($cache['params']);
                get_cache($cache['cache_name'], $cache['query'], $params, 0, 1);

                $stmtDel = $db->prepare("DELETE QUICK FROM cache_queue WHERE cache_name = :cache_name");
                $stmtDel->execute(array(':cache_name' => $cache['cache_name']));

                $cached[] = $cache['cache_name'];
            }
        }

        return array('cached' => $cached, 'not_cached' => $not_cached);
    } else {
        return array('cached' => array(), 'not_cached' => array());
    }
}
