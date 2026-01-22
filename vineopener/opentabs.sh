#!/bin/bash

URL_FILE="urls.txt"

while IFS= read -r url; do
    echo "Opening $url"
    open -na "Google Chrome" --args "$url"
    sleep 1
done < "$URL_FILE"