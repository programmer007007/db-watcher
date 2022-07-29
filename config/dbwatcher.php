<?php

return [
    "db_name" => env("DB_DATABASE"),
    "tables_to_track" => [
        ["table" => "bingo_venues", "column" => ["venue_location"], "track_event" => ["insert", "update"]]
        // table is required | column if empty will track all columns | track_event is optional | track_event is an array of events to track if empty will track all events
    ],
    "log_table_name" => 'strongbond_log',
    "common_columns_to_ignore" => ["created_at", "updated_at", "deleted_at"]
];

