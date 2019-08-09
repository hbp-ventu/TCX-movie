<?php

if (count($argv) != 3) {
    echo "usage: heatmap-movie.php <tcxs> <output>\n";
    echo "\n";
    echo "   <tcxs>    path to directory holding tcx files with names <date>-<time>.tcx\n";
    echo "   <output>  the name of the output AVI file\n";
    exit;
}

$tcxs = $argv[1]; // where the .tcx files are stored
$filename = $argv[2];

$zoom = 10; // map zoom
$saveas = "/tmp/heatmap-frames/"; // temp storage for frames
$cache = "/tmp/osm-cache/"; // temp storage for tiles
$tilesize = 256; // always 256 for openstreetmaps
$fadeout = 5; // set to 0 to disable fadeout
$colour = [200,20,150];
$startcolour = [200,200,0];
$gop = 25; // frames per "group of pictures"

// create directories, clean up
if (!is_dir($cache))   mkdir($cache);
if (!is_dir($saveas))   mkdir($saveas);
system("rm -Rf $saveas/*.png");

// find files
$files = array();
$dh = opendir($tcxs);
while ($dh && $f = readdir($dh)) {
    if (!$f)   break;
    if (substr($f, 0, 1) == '.')  continue;
    if (substr($f, -4) != '.tcx')  continue;
    $files[] = $f;
}
if ($dh)  closedir($dh);

// load files and read points
$dates = array();
echo "reading data\n";
$numpoints = 0;
foreach ($files as $f) {
    $xml = simplexml_load_file("$tcxs$f");
    if (!($xml instanceof SimpleXMLElement))   continue;

    echo "reading $f\n";

    $date = substr($f, 0, 15);
    foreach ($xml->Activities as $activity) {
        foreach ($activity->Activity as $lap) {
            foreach ($lap->Lap as $track) {
                foreach ($track->Track as $trackpoint) {
                    foreach ($trackpoint->Trackpoint as $p) {
                        foreach ($p->Position as $pos) {
                            $lat = (float)$pos->LatitudeDegrees;
                            $lon = (float)$pos->LongitudeDegrees;
                            $dates[$date][] = array($lat, $lon);
                            $numpoints++;
                        }
                    }
                }
            }
        }
    }
}

ksort($dates);

echo "$numpoints points\n";

// calc. bounding box
$minlat = false;  $minlon = false;
$maxlat = false;  $maxlon = false;
foreach ($dates as $date => $pts) {
    foreach ($pts as $pt) {
        $lat = $pt[0];
        $lon = $pt[1];
        if ($lat < $minlat || $minlat === false)   $minlat = $lat;
        if ($lat > $maxlat || $maxlat === false)   $maxlat = $lat;
        if ($lon < $minlon || $minlon === false)   $minlon = $lon;
        if ($lon > $maxlon || $maxlon === false)   $maxlon = $lon;
    }
}
echo "coords: $minlat,$minlon - $maxlat,$maxlon\n";

list($minx, $miny) = coord_to_pixel($minlon, $minlat, $zoom);
list($maxx, $maxy) = coord_to_pixel($maxlon, $maxlat, $zoom);

// which tiles to include
$tileminx = floor($minx);
$tileminy = floor($maxy);
$tilemaxx = ceil($maxx);
$tilemaxy = ceil($miny);

$width = $tilemaxx - $tileminx;
$height = $tilemaxy - $tileminy;
echo "size: $width x $height\n";

// create background and fill it in
$img = imagecreatetruecolor($width*$tilesize, $height*$tilesize);
$col = imagecolorallocate($img, $colour[0], $colour[1], $colour[2]);
for ($tilex = $tileminx; $tilex <= $tilemaxx; $tilex++)
    for ($tiley = $tileminy; $tiley <= $tilemaxy; $tiley++) {
        $pngfile = fetch_tile($tilex, $tiley, $zoom);
        if (!$pngfile)   continue;
        $tile = @imagecreatefrompng($pngfile);
        if (!is_resource($tile))   continue;
        imagecopy($img, $tile, ($tilex-$tileminx)*$tilesize, ($tiley-$tileminy)*$tilesize, 0, 0, $tilesize, $tilesize);
        imagedestroy($tile);
    }

// prepare text
$txtfg = imagecolorallocate($img, 0,0,0);
$txtbg = imagecolorallocate($img, 255,255,255);
$txtx = $tilesize*$width-200;
$txty = 10;

// prepare fading out
if ($fadeout) {
    $colours = [];
    $re = $colour[0];
    $ge = $colour[1];
    $be = $colour[2];
    $rs = $startcolour[0];
    $gs = $startcolour[1];
    $bs = $startcolour[2];
    for ($i = 0; $i < $fadeout; $i++) { // 0=start colour, $fadeout.1=end colour
         $p = ($fadeout-1 - $i)/($fadeout-1);
         $r = $re*$p + $rs*(1-$p);
         $g = $ge*$p + $gs*(1-$p);
         $b = $be*$p + $bs*(1-$p);
         echo "colour $i: $r $g $b\n";
         $colours[$i] = imagecolorallocate($img, $r, $g, $b);
    }
}

// draw all points
$frameno = 0;
$totalkms = 0;
$prevdates = array();
foreach ($dates as $date => $pts) {
    echo "drawing $date\n";
    
    $prevdates[] = $pts;
    if (count($prevdates) > $fadeout)    array_shift($prevdates);
    
    // draw the tracks for previous dates
    for ($i = 0; $i < count($prevdates); $i++) { // 0 is the oldest track
        $dist = draw_route($prevdates[$i], $colours[$i]);
        if ($i == count($prevdates)-1)   $totalkms += $dist;
    }
 
    // draw date+distance in top right corner
    $date = substr($date,0,4).'-'.substr($date,4,2).'-'.substr($date,6,2);
    $dist = sprintf("%.1f kms", $totalkms);
    imagefilledrectangle($img, $txtx, $txty, $txtx + 200, $txty + 16, $txtbg);
    imagestring($img, 4, $txtx, $txty, "$date, $dist", $txtfg);
    
    // save
    $fname = sprintf("$saveas/%08d.png", $frameno++);
    imagepng($img, $fname);
}

// show last frame a few extra times
for ($i = 0; $i < $gop; $i++) {
    $fname = sprintf("$saveas/%08d.png", $frameno++);
    imagepng($img, $fname);
}

system("mencoder \"mf://$saveas/*.png\" -mf fps=4 -o $filename -ovc lavc -nosound -lavcopts vcodec=mpeg4:keyint=$gop");


function draw_route($pts, $col) {
   global $img, $zoom, $tileminx, $tileminy, $tilesize;
   $length = 0;
   
   foreach ($pts as $idx => $pt) {
        list($x, $y) = coord_to_pixel($pt[1], $pt[0], $zoom);

        $ix = $tilesize * ($x - $tileminx);
        $iy = $tilesize * ($y - $tileminy);
        imagefilledellipse($img, $ix, $iy, 2, 2, $col);
        
        if ($idx > 0) {
            $prev = $pts[$idx-1];
            $length += get_distance($pt[1], $pt[0], $prev[1], $prev[0]);
        }
    }
    return $length/1000; // length in kms
}

function coord_to_pixel($lon, $lat, $zoom) {
    $num_tiles = pow(2, $zoom);

    $x = ($lon+180.0)/360.0;
    $lat = ((double)$lat)*M_PI/180.0;
    $y = (1.0 - log(tan($lat) + 1.0/cos($lat))/M_PI)/2.0;
    return array($num_tiles*$x, $num_tiles*$y);
}

function fetch_tile($x, $y, $zoom) {
    global $cache;

    $file = $cache."/$zoom-$x-$y.png";
    if (file_exists($file)) {
        // we only cache tiles for about 1 month; we use a random value
        // to determine when a tile is too old, to prevent all tiles
        // from expiring at the same time
        $too_old_minutes = 31*24*60+rand(-8*60, 8*60);
        if (filemtime($file) > time()-$too_old_minutes*60)
            return $file;
    }
    $url = "https://tiles.microbizz.dk/?s=c&z=$zoom&x=$x&y=$y";
    echo "fetching tile $url\n";
    $png = file_get_contents($url);
    if (!$png)   return false;
    file_put_contents($file, $png);
    return $file;
}

function get_distance($lat1, $lon1, $lat2, $lon2) {
    if (($lat1 == 0 && $lon1 == 0) || ($lat2 == 0 && $lon2 == 0)) return -1;
    $radians = 180.0 / 3.14159265;
    if ($lat1 == $lat2 && $lon1 == $lon2)
        return 0.0;
    $lat1 /= $radians;
    $lon1 /= $radians;
    $lat2 /= $radians;
    $lon2 /= $radians;

    $dist = acos(sin($lat1) * sin($lat2) + cos($lat1) * cos($lat2) * cos($lon2 - $lon1));
    return $dist * 6370031.0;
}

?>
