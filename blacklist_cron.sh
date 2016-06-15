#!/bin/bash

##############################################################################
# This script will updated the blacklist from source data and write a static
# text file to the path of your choosing
#
# Example usage:
#       blacklist_cron.sh -o /var/www/html/blacklist.txt | logger -t blacklister -p local0.info
#
# Author: Adam Franco
# Date: 2014-04-11
# License: http://www.gnu.org/copyleft/gpl.html GNU General Public License (GPL)
##############################################################################

usage="Usage: `basename $0` -o <output file>"

# Set up options
while getopts ":o:" options; do
        case $options in
        o ) output=$OPTARG;;
        \? ) echo -e $usage
                exit 1;;
        * ) echo -e $usage
                exit 1;;

        esac
done

if [ -n "$output" ]
then
	DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
	$DIR/update_list.php

	if [ ! -f "$output" ]
	then
		touch $output
	fi

	if [ ! -w "$output" ]
	then
		echo "Error: Output file $output is not writeable."
		exit 3
	fi

	$DIR/get_list.php > $output

else
	echo "Error: You must specify an output file."
	echo -e $usage
	exit 2
fi
