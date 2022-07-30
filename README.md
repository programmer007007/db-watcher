# db-watcher
PHP package to keep tracks of modification that happens on your tables.

##Note: this works based on triggers. So even the modification that are happening directly on table via some ide are also tracked.


## Step 0 : php artisan vendor:publish 
        
        // This step will put the config file [dbwatcher.php] in the config folder.
        Below is the content in config file
        return [
        "db_name" => env("DB_DATABASE"),
        "tables_to_track" => [
        ["table" => "supplier_master", "column" => ["supplier_name","qnty"], "track_event" => ["insert", "update","delete]]
        // table name for which tracking is required, can't be left blank
        // column key if empty will track all columns 
        // track_event is optional | track_event is an array of events to track if empty will track all events
        ],
        "log_table_name" => 'sb_log',
        "common_columns_to_ignore" => ["created_at", "updated_at", "deleted_at"]
        ];
        
## Step 1 : Create a new object
        $db_tracker_obj = new DBWatcher();
        
## Step 2 : Generate the tracking tables | once done you can skip this step | 2 tables are created 
        
        $db_tracker_obj->drop_recreate_log_tables();
        
## Step 3 : Generate the tracking triggers | once any changes are done in the dbwatcher.php config file you need to run this step agian for new changes to reflect.
        
        $db_tracker_obj->refreshTracker();
        
## Step 4 : Set the user_id for the user who is going to be modifying the db. if you miss this step root@localhost will be used.
        
        $db_tracker_obj->action_performed_by_user(5);
        
## Step 5 : Perform the CRUD operations.
        
        DB::table("venues")->insert([
            "venue_name" => "Carnival",
            "venue_location" => "Mumbai",         
            "created_at" => Carbon::now()->toDateTimeString(),
            "updated_at" => Carbon::now()->toDateTimeString()
        ]);
        
## Step 6 : Unset the user_id after the CRUD operations.
        
        $db_tracker_obj->unset_action_performed_by_user();
        
        
