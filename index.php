<?php

include_once('config.php');

include_once('module/database.php');

include_once('module/cache.php');

batch_cache();

store_cache_queue();