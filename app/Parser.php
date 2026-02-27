<?php

namespace App;

use Exception;
use SplFileObject;
use App\Commands\Visit;

final class Parser
{
    static $READ_CHUNK = 500_000;
    static $CORES = 8;

    public function partParse(string $inputPath, int $start, int $length, $output, $dates, $paths, $pathCount) {
        $left = "";
        $read = 0;

        $file = new SplFileObject($inputPath);
        $file->setFlags(SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY);
        $file->fseek($start);

        $order = [];

        while (!$file->eof() && $read < $length) {
            $lenAsked = $read + Parser::$READ_CHUNK >= $length ? $length - $read : Parser::$READ_CHUNK;
            $buffer = $file->fread($lenAsked);

            if(substr($buffer, -1) != "\n") {
                $extra = $file->fgets()."\n";
                $lenAsked += strlen($extra);
                $buffer .= $extra;
            }

            $nextPos = -1;
            if($start == 0) {
                while($nextPos+10 < $lenAsked) {
                    $pos = $nextPos;
                    $nextPos = strpos($buffer, "\n", $nextPos + 52);
                    
                    $path = substr($buffer, $pos + 26, $nextPos - $pos - 52);
                    $date = substr($buffer, $nextPos - 22, 7);

                    $dateId = $dates[$date];
                    $pathId = $paths[$path];
                    
                    $output[$dateId*$pathCount+$pathId]++;
                    
                    $order[$pathId] = true;
                }
            }
            else {
                while($nextPos+10 < $lenAsked) {
                    $pos = $nextPos;
                    $nextPos = strpos($buffer, "\n", $nextPos + 52);
                    
                    $path = substr($buffer, $pos + 26, $nextPos - $pos - 52);
                    $date = substr($buffer, $nextPos - 22, 7);

                    $dateId = $dates[$date];
                    $pathId = $paths[$path];
                    
                    $output[$dateId*$pathCount+$pathId]++;
                }
            }

            $read += $lenAsked;
        }

        return $this->convert($output, $dates, $order);
    }

    public function convert($input, $dates, $order) {
        return pack("v*", ...$input).pack("v*", ...array_keys($order));
    }

    public function partParallel(string $inputPath, int $start, int $length, $output, $dates, $paths, $pathCount) {
        list($readChannel, $writeChannel) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();

        if ($pid == 0) {
            fclose($readChannel);
            $output = $this->partParse($inputPath, $start, $length, $output, $dates, $paths, $pathCount);
            fwrite($writeChannel, $output);
            exit();
        }

        fclose($writeChannel);
        return [$pid, $readChannel];
    }

    public function partReadParallel($thread, $outputLength) {
        
        $output = "";
        while(!feof($thread[1])) {
            $output .= fread($thread[1], Parser::$READ_CHUNK);
        }

        return [substr($output, 0, $outputLength*2), unpack("v*", substr($output, $outputLength*2))];
    }

    public function parse(string $inputPath, string $outputPath): void
    {
        gc_disable();

        // Prepare arrays
        $m2d = [0, 32, 30, 32, 31, 32, 31, 32, 32, 31, 32, 31, 32];
        $numbers = ["", "01", "02", "03", "04", "05", "06", "07", "08", "09", "10", "11", "12", "13", "14", "15", "16", "17", "18", "19", "20", "21", "22", "23", "24", "25", "26", "27", "28", "29", "30", "31"];

        $dates = [];
        $dateCount = 0;
        for($y=0; $y!=6; $y++) {
            for($m=1; $m!=13; $m++) {
                $max = $m2d[$m];
                for($d=1; $d!=$max; $d++) {
                    $date = $y."-".$numbers[$m]."-".$numbers[$d];
                    $dates[$date] = $dateCount++;
                }
            }
        }
        for($m=1; $m!=3; $m++) {
            $max = $m2d[$m];
            for($d=1; $d!=$max; $d++) {
                $date = "6-".$numbers[$m]."-".$numbers[$d];
                $dates[$date] = $dateCount++;
            }
        }

        $paths = [];
        $pathCount = 0;
        foreach(Visit::all() as $page) {
            $uri = substr($page->uri, 25);
            $paths[$uri] = $pathCount++;
        }

        $output = array_fill(0, $pathCount*$dateCount, 0);

        // Determine ranges
        $ranges = [];
        $start = 0;
        $file = new SplFileObject($inputPath);
        $file->setFlags(SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY);
        $length = ceil(filesize($inputPath)/Parser::$CORES);
        for($i=0; $i!=Parser::$CORES; $i++) {
            $file->fseek($length*$i+$length);
            $file->fgets();
            $end = $file->ftell();
            $ranges[$i] = [$start, $end];
            $start = $end;
        }

        // Start threads
        $threads = [];
        for($i=0; $i!=Parser::$CORES; $i++) {
            $threads[$i] = $this->partParallel($inputPath, $ranges[$i][0], $ranges[$i][1]-$ranges[$i][0], $output, $dates, $paths, $pathCount);
        }

        // Precompute while waiting
        $datesJson = [];
        foreach($dates as $date => $dateI) {
            $datesJson[$dateI] = "\n        \"202".$date.'": ';
        }

        $pathsJson = [];
        foreach($paths as $path => $pathI) {
            $fullPath = "/blog/".$path;
            $pathsJson[$pathI] = "\n    \"".str_replace('/', '\\/', $fullPath).'": {';
        }

        // Read threads
        $sortedPaths = [];
        for($i=1; $i!=Parser::$CORES; $i++) {
            list($data) = $this->partReadParallel($threads[$i], $pathCount*$dateCount);
            for($j=0; $j!=$pathCount*$dateCount*2; $j+=2) {
                $first = ord($data[$j]);
                $second = ord($data[$j+1]);
                $output[$j/2] += $first + $second*256;
            }
        }
        list($data, $sortedPaths) = $this->partReadParallel($threads[0], $pathCount*$dateCount);
        for($j=0; $j!=$pathCount*$dateCount*2; $j+=2) {
            $first = ord($data[$j]);
            $second = ord($data[$j+1]);
            $output[$j/2] += $first + $second*256;
        }

        // Merge
        $buffer = "{";
        $pathComma = "";
        foreach($sortedPaths as $pathI) {
            $buffer .= $pathComma.$pathsJson[$pathI];
            $dateComma = "";
            foreach($dates as $date => $dateI) {
                $count = $output[$pathI+$dateI*$pathCount];
                if($count != 0) {
                    $buffer .= $dateComma.$datesJson[$dateI].$count;
                    $dateComma = ",";
                }
            }

            $buffer .= "\n    }";
            $pathComma = ",";
        }
        $buffer .= "\n}";

        file_put_contents($outputPath, $buffer);
    }
}