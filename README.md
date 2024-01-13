# heatmap video
Small PHP script for generating a video from a sequence of .tcx files, from Endomondo or similar.

Requires that "mencoder" and codecs are installed. 
Uses Openstreetmaps or a similar tile server for background; you will need to provide the URL for the server.

Save the .tcx files to a suitable directory, then run the script like this:
  php heatmap-movie.php <directory> <output.avi>

Can be quite slow the first time, as the tiles have to be fetched from the tile server.
