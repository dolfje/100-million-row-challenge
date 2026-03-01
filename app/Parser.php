<?php

namespace App;

use Exception;
use SplFileObject;
use App\Commands\Visit;

final class Parser
{
    static $READ_CHUNK = 500_000;
    static $CORES = 8;

    public function partParse(string $inputPath, int $start, int $length, $dates, $paths, $pathCount, $dateCount) {
        $left = "";
        $read = 0;

        $output = str_repeat(chr(0), $pathCount*$dateCount);

        $next = [];
        for($i=0; $i!=255;$i++) {
            $next[chr($i)] = chr($i+1);
        }

        $file = new SplFileObject($inputPath);
        $file->setFlags(SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY);
        $file->fseek($start);

        $order = [];
        $chunks = 0;
        while (!$file->eof() && $read < $length) {
            $lenAsked = $read + Parser::$READ_CHUNK >= $length ? $length - $read : Parser::$READ_CHUNK;
            $buffer = $file->fread($lenAsked);

            if(substr($buffer, -1) != "\n") {
                $extra = $file->fgets()."\n";
                $lenAsked += strlen($extra);
                $buffer .= $extra;
            }

            $nextPos = -1;
            if($start == 0 && $chunks++ < 2) {
                while($nextPos+10 < $lenAsked) {
                    $pos = $nextPos;
                    $nextPos = strpos($buffer, "\n", $nextPos + 52);
                    
                    $path = substr($buffer, $pos + 30, $nextPos - $pos - 56);
                    $date = substr($buffer, $nextPos - 22, 7);

                    $pathId = $paths[$path];
                    
                    $index = $dates[$date]*$pathCount+$pathId;
                    $output[$index] = $next[$output[$index]];
                    
                    $order[$pathId] = true;
                }
            }
            else {
                while($nextPos+10 < $lenAsked-470_000) {
                    $pos = $nextPos;
                    $nextPos = strpos($buffer, "\n", $nextPos + 52);
                    $index = $dates[substr($buffer, $nextPos - 22, 7)]*$pathCount+$paths[substr($buffer, $pos + 30, $nextPos - $pos - 56)];
                    $output[$index] = $next[$output[$index]];

                    $pos = $nextPos;
                    $nextPos = strpos($buffer, "\n", $nextPos + 52);
                    $index = $dates[substr($buffer, $nextPos - 22, 7)]*$pathCount+$paths[substr($buffer, $pos + 30, $nextPos - $pos - 56)];
                    $output[$index] = $next[$output[$index]];

                    $pos = $nextPos;
                    $nextPos = strpos($buffer, "\n", $nextPos + 52);
                    $index = $dates[substr($buffer, $nextPos - 22, 7)]*$pathCount+$paths[substr($buffer, $pos + 30, $nextPos - $pos - 56)];
                    $output[$index] = $next[$output[$index]];

                    $pos = $nextPos;
                    $nextPos = strpos($buffer, "\n", $nextPos + 52);
                    $index = $dates[substr($buffer, $nextPos - 22, 7)]*$pathCount+$paths[substr($buffer, $pos + 30, $nextPos - $pos - 56)];
                    $output[$index] = $next[$output[$index]];

                    $pos = $nextPos;
                    $nextPos = strpos($buffer, "\n", $nextPos + 52);
                    $index = $dates[substr($buffer, $nextPos - 22, 7)]*$pathCount+$paths[substr($buffer, $pos + 30, $nextPos - $pos - 56)];
                    $output[$index] = $next[$output[$index]];

                    $pos = $nextPos;
                    $nextPos = strpos($buffer, "\n", $nextPos + 52);
                    $index = $dates[substr($buffer, $nextPos - 22, 7)]*$pathCount+$paths[substr($buffer, $pos + 30, $nextPos - $pos - 56)];
                    $output[$index] = $next[$output[$index]];

                    $pos = $nextPos;
                    $nextPos = strpos($buffer, "\n", $nextPos + 52);
                    $index = $dates[substr($buffer, $nextPos - 22, 7)]*$pathCount+$paths[substr($buffer, $pos + 30, $nextPos - $pos - 56)];
                    $output[$index] = $next[$output[$index]];

                    $pos = $nextPos;
                    $nextPos = strpos($buffer, "\n", $nextPos + 52);
                    $index = $dates[substr($buffer, $nextPos - 22, 7)]*$pathCount+$paths[substr($buffer, $pos + 30, $nextPos - $pos - 56)];
                    $output[$index] = $next[$output[$index]];
                }

                while($nextPos+10 < $lenAsked) {
                    $pos = $nextPos;
                    $nextPos = strpos($buffer, "\n", $nextPos + 52);
                    $index = $dates[substr($buffer, $nextPos - 22, 7)]*$pathCount+$paths[substr($buffer, $pos + 30, $nextPos - $pos - 56)];
                    $output[$index] = $next[$output[$index]];
                }
            }

            $read += $lenAsked;
        }

        return $this->convert($output, $dates, $order);
    }

    public function convert($input, $dates, $order) {
        return $input.pack("v*", ...array_keys($order));
    }

    public function partParallel(string $inputPath, int $start, int $length, $dates, $paths, $pathCount, $dateCount) {
        list($readChannel, $writeChannel) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();

        if ($pid == 0) {
            fclose($readChannel);
            $output = $this->partParse($inputPath, $start, $length, $dates, $paths, $pathCount, $dateCount);
            fwrite($writeChannel, $output);
            exit();
        }

        fclose($writeChannel);
        return $readChannel;
    }

    public function partReadParallel($thread, $outputLength) {
        $output = "";
        while(!feof($thread)) {
            $output .= fread($thread, Parser::$READ_CHUNK);
        }

        return [substr($output, 0, $outputLength), unpack("v*", substr($output, $outputLength))];
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
            $uri = substr($page->uri, 29);
            $paths[$uri] = $pathCount++;
        }

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
            $threads[$i] = $this->partParallel($inputPath, $ranges[$i][0], $ranges[$i][1]-$ranges[$i][0], $dates, $paths, $pathCount, $dateCount);
        }

        // Precompute while waiting
        $datesJson = [];
        foreach($dates as $date => $dateI) {
            $datesJson[$dateI] = "\n        \"202".$date.'": ';
        }

        $pathsJson = [];
        foreach(Visit::all() as $page) {
            $uri = substr($page->uri, 25);
            $short = substr($page->uri, 29);
            $pathsJson[$paths[$short]] = "\n    \"\\/blog\\/".$uri.'": {';
        }

        $output = array_fill(0, $pathCount*$dateCount, 0);

        // Read threads
        $first = $threads[0];
        $read = []; $write = []; $except = [];
        while(count($threads) != 0) {
            $read = $threads;
            stream_select($read, $write, $except, 5);
            foreach($read as $i => $thread) {
                if($thread == $first) {
                    list($data, $sortedPaths) = $this->partReadParallel($thread, $pathCount*$dateCount);
                }
                else {
                    list($data) = $this->partReadParallel($thread, $pathCount*$dateCount);
                }

                for($j=0; $j!=$pathCount*$dateCount; $j+=1) {
                    $output[$j] += ord($data[$j]);
                }
                unset($threads[$i]);
            }
        }

        // Merge
        $buffer = "{";
        $pathComma = "";
        for($i=1; $i!=$pathCount+1; $i++) {
            $pathI = $sortedPaths[$i];
            $buffer .= $pathComma.$pathsJson[$pathI];
            $dateComma = "";
            
            for($j=0; $j!=$dateCount; $j++) {
                $count = $output[$pathI+$j*$pathCount];
                if($count != 0) {
                    $buffer .= $dateComma.$datesJson[$j].$count;
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