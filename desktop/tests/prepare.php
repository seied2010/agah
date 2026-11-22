<?php
if(!is_dir('/state'))mkdir('/state',0777,true);
file_put_contents('/state/settings.json',json_encode(['root_password'=>'Local-Only-Test-Root!','db_password'=>bin2hex(random_bytes(32)),'admin_password'=>'Local-UI-Test-2026!','enc_key'=>bin2hex(random_bytes(32)),'salts'=>bin2hex(random_bytes(64)),'db_host'=>'127.0.0.1','db_port'=>3306,'url'=>'http://127.0.0.1:18817']));
