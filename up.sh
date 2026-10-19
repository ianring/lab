#!/bin/bash

# rsync -avh -e ssh /Users/iring/Documents/Repos/bitbucket/iring/lab root@138.197.153.40:/root
# rsync -avh -e ssh /Users/iring/Documents/Repos/github/ianring/lab/shirtme root@138.197.153.40:/root/lab
# rsync -avh -e ssh /Users/iring/Documents/Repos/github/ianring/lab/numino root@138.197.153.40:/root/lab
# rsync -avh -e ssh /Users/iring/Documents/Repos/github/ianring/lab/relief root@138.197.153.40:/root/lab
# rsync -avh -e ssh /Users/iring/Documents/Repos/github/ianring/lab/cubes root@138.197.153.40:/root/lab
# rsync -avh -e ssh /Users/iring/Documents/Repos/github/ianring/lab/trackplay root@138.197.153.40:/root/lab
# rsync -avh -e ssh /Users/iring/Documents/Repos/github/ianring/lab/arrowgame root@138.197.153.40:/root/lab
# rsync -avh -e ssh /Users/ian/Documents/Repos/github/ianring/lab/pathme root@i-a-n.ca:/root/lab
# rsync -avh -e ssh /Users/ian/Documents/Repos/github/ianring/lab/3d root@i-a-n.ca:/root/lab
# rsync -avh -e ssh /Users/ian/Documents/Repos/github/ianring/lab/kiln root@i-a-n.ca:/root/lab

# rsync -avh -e ssh /Users/ian/Documents/Repos/github/ianring/lab/kiln root@ianring.com:/var/www/darkware.ca/public_html/

# Connect4 writes nothing at runtime — the game state travels in the URL — so
# there is no data directory to exclude and no ownership to set afterwards.
# rsync -avh -e ssh /Users/ian/Documents/Repos/github/ianring/lab/connect4 root@i-a-n.ca:/root/lab

# rsync -avh -e ssh /Users/ian/Documents/Repos/github/ianring/lab/sudoku root@i-a-n.ca:/root/lab
# rsync -avh -e ssh /Users/ian/Documents/Repos/github/ianring/lab/fruit root@i-a-n.ca:/root/lab
# rsync -avh -e ssh /Users/ian/Documents/Repos/github/ianring/lab/pathme root@i-a-n.ca:/root/lab
# rsync -avh -e ssh /Users/ian/Documents/Repos/github/ianring/lab/sucker root@i-a-n.ca:/root/lab
rsync -avh -e ssh /Users/ian/Documents/Repos/github/ianring/lab/puzzlebook root@i-a-n.ca:/root/lab
rsync -avh -e ssh /Users/ian/Documents/Repos/github/ianring/lab/puzzlebook2 root@i-a-n.ca:/root/lab

# ssh -T root@138.197.153.40 'cd /root/book/assets && php convert-svg-assets.php'
exit
