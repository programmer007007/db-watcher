# db-watcher
PHP package to keep tracks of modification that happens on your tables. 


## Step 0 : php artisan vendor:publish 
        
        // This step will put the config file [dbwatcher.php] in the config folder.
        
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
        
        
