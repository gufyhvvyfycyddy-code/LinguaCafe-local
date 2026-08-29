#!/bin/sh

# Folders needed for persistence
folder_paths="
    ./storage/app/dictionaries
    ./storage/app/fonts
    ./storage/app/images/book_images
    ./storage/app/public
    ./storage/app/temp/dictionaries
    ./storage/framework/cache/data
    ./storage/framework/sessions
    ./storage/framework/testing
    ./storage/framework/views
    ./storage/logs
    ./storage/backup
"

# Ensure the folders exist
for folder_path in $folder_paths; do
    if [ ! -d "$folder_path" ]; then
        mkdir -p "$folder_path"
        echo "Folder created: $folder_path"
    else
        echo "Folder already exists: $folder_path"
    fi
done

# Production schema changes are a separate, explicitly controlled deployment step.
# Container restarts must not mutate or seed the database implicitly.
exec "$@"
