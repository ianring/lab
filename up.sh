#!/bin/bash

# rsync -avh -e ssh /Users/iring/Documents/Repos/bitbucket/iring/lab root@138.197.153.40:/root
# rsync -avh -e ssh /Users/iring/Documents/Repos/github/ianring/lab/shirtme root@138.197.153.40:/root/lab
# rsync -avh -e ssh /Users/iring/Documents/Repos/github/ianring/lab/numino root@138.197.153.40:/root/lab
# rsync -avh -e ssh /Users/iring/Documents/Repos/github/ianring/lab/relief root@138.197.153.40:/root/lab
# rsync -avh -e ssh /Users/iring/Documents/Repos/github/ianring/lab/cubes root@138.197.153.40:/root/lab
# rsync -avh -e ssh /Users/iring/Documents/Repos/github/ianring/lab/trackplay root@138.197.153.40:/root/lab
# rsync -avh -e ssh /Users/iring/Documents/Repos/github/ianring/lab/arrowgame root@138.197.153.40:/root/lab
# rsync -avh -e ssh /Users/ian/Documents/Repos/github/ianring/lab/3d root@i-a-n.ca:/root/lab
# rsync -avh -e ssh /Users/ian/Documents/Repos/github/ianring/lab/kiln root@i-a-n.ca:/root/lab

rsync -avh -e ssh /Users/ian/Documents/Repos/github/ianring/lab/kiln root@ianring.com:/var/www/darkware.ca/public_html/

# Promptpix keeps its prompts and every generated image in data/, so deploys
# skip it: uploading must not clobber the images, nor reset the ownership that
# lets the web server write there. Create it once on the server and chown it.
# rsync -avh --exclude 'data/' -e ssh /Users/ian/Documents/Repos/github/ianring/lab/promptpix root@ianring.com:/var/www/darkware.ca/public_html/

rsync -avh --exclude 'data/' -e ssh /Users/ian/Documents/Repos/github/ianring/lab/promptpix root@i-a-n.ca:/root/lab

# ssh -T root@138.197.153.40 'cd /root/book/assets && php convert-svg-assets.php'
exit
