[www]
user = www-data
group = www-data
listen = {{php_sock}}
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = {{pm_max_children}}
pm.start_servers = {{pm_start}}
pm.min_spare_servers = 1
pm.max_spare_servers = {{pm_spare}}
pm.max_requests = 500
catch_workers_output = yes
clear_env = no
php_admin_value[memory_limit] = {{memory_limit}}
