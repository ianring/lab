#!/bin/bash

# rsync -avh -e ssh /Users/iring/Documents/Repos/bitbucket/iring/lab root@138.197.153.40:/root
# rsync -avh -e ssh /Users/iring/Documents/Repos/github/ianring/lab/shirtme root@138.197.153.40:/root/lab
# rsync -avh -e ssh /Users/iring/Documents/Repos/github/ianring/lab/numino root@138.197.153.40:/root/lab
# rsync -avh -e ssh /Users/iring/Documents/Repos/github/ianring/lab/relief root@138.197.153.40:/root/lab
# rsync -avh -e ssh /Users/iring/Documents/Repos/github/ianring/lab/cubes root@138.197.153.40:/root/lab
# rsync -avh -e ssh /Users/iring/Documents/Repos/github/ianring/lab/trackplay root@138.197.153.40:/root/lab
# rsync -avh -e ssh /Users/iring/Documents/Repos/github/ianring/lab/arrowgame root@138.197.153.40:/root/lab
rsync -avh -e ssh /Users/ian/Documents/Repos/github/ianring/lab/3d root@138.197.153.40:/root/lab

# ssh -T root@138.197.153.40 'cd /root/book/assets && php convert-svg-assets.php'
exit
