<?php

return [
    "db_name" => env("DB_DATABASE"),
    "tables_to_track" => [
        ["table" => "supplier_master", "column" => ["supplier_name", "qnty"], "track_event" => ["insert", "update", "delete"]]
    ],
    "log_table_name" => 'sb_log',
    "common_columns_to_ignore" => ["created_at", "updated_at", "deleted_at"]
];

