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
            $part = $file->fread($lenAsked);

            $extra = "";
            if(substr($part, -1) != "\n") {
                $extra = $file->fgets()."\n";
                $lenAsked += strlen($extra);
            }            

            $buffer = $part . $extra;

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

        pcntl_waitpid($thread[0], $status);

        $status;
        return [substr($output, 0, $outputLength*2), unpack("v*", substr($output, $outputLength*2))];
    }

    public function parse(string $inputPath, string $outputPath): void
    {
        gc_disable();

        // Prepare arrays
        $dates = [];
        $datesReverse = [];
        $dateCount = 0;
        for($y=0; $y!=6; $y++) {
            for($m=1; $m!=13; $m++) {
                for($d=1; $d!=32; $d++) {
                    $date = $y."-".str_pad($m, 2, "0", STR_PAD_LEFT)."-".str_pad($d, 2, "0", STR_PAD_LEFT);
                    $datesReverse[$dateCount] = $date;
                    $dates[$date] = $dateCount++;
                }
            }
        }
        for($m=1; $m!=3; $m++) {
            for($d=1; $d!=32; $d++) {
                $date = "6-".str_pad($m, 2, "0", STR_PAD_LEFT)."-".str_pad($d, 2, "0", STR_PAD_LEFT);
                $datesReverse[$dateCount] = $date;
                $dates[$date] = $dateCount++;
            }
        }

        $paths = [];
        $pathsReverse = [];
        $pathCount = 0;
        foreach(Visit::all() as $page) {
            $uri = substr($page->uri, 25);
            $pathsReverse[$pathCount] = $uri;
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

        // Read threads
        $sortedPaths = [];
        $outputs = [];
        for($i=0; $i!=Parser::$CORES; $i++) {
            $output = $this->partReadParallel($threads[$i], $pathCount*$dateCount);
            $outputs[$i] = $output[0];

            if($i==0) {
                $sortedPaths = $output[1];
            }
        }

        // Merge
        $merged = [];
        for($j=1; isset($sortedPaths[$j]); $j++) {
            $pathI = $sortedPaths[$j];
            $path = $pathsReverse[$pathI];
            $fullPath = "/blog/".$path;
            $merged[$fullPath] = [];

            for($dateI=1; $dateI!=$dateCount; $dateI++) {
                $count = 0;
                for($i=0; $i!=Parser::$CORES; $i++) {
                    $index = $pathI+$dateI*$pathCount;
                    $first = ord($outputs[$i][$index*2]);
                    $second = ord($outputs[$i][$index*2+1]);

                    $count += $first + $second*256;
                }

                if($count != 0) {
                    $date = $datesReverse[$dateI];
                    $merged[$fullPath]["202".$date] = $count;
                }
            }
        }

        unset($outputs);
        file_put_contents($outputPath, json_encode($merged, JSON_PRETTY_PRINT));
    }
}