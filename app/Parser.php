<?php

namespace App;

use Exception;
use SplFileObject;
use App\Commands\Visit;

final class Parser
{
    static $READ_CHUNK = 500_000;
    static $CORES = 8;

    public function partParse(string $inputPath, int $start, int $length, $output, $dates) {
        $left = "";
        $read = 0;

        $file = new SplFileObject($inputPath);
        $file->setFlags(SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY);
        $file->fseek($start);

        $order = [];

        while (!$file->eof() && $read != $length) {
            $lenAsked = $read + Parser::$READ_CHUNK >= $length ? $length - $read : Parser::$READ_CHUNK;
            $part = $file->fread($lenAsked);
            $buffer = $left . $part;

            $pos = -1;
            $nextPos = strpos($buffer, "\n", $pos+1+30);
            if($start == 0) {
                while($nextPos !== false) {
                    $i = $nextPos - 26;
                    
                    $jump = $pos+26;
                    $path = substr($buffer, $jump, $i-$jump);
                    $date = substr($buffer, $i+4, 7);

                    $dateId = $dates[$date];
                    $output[$path][$dateId]++;
                    
                    $order[$path] = true;

                    $pos = $nextPos;
                    $nextPos = strpos($buffer, "\n", $nextPos+1);
                }
            }
            else {
                while($nextPos !== false) {
                    $i = $nextPos - 26;

                    $jump = $pos+26;
                    $path = substr($buffer, $jump, $i-$jump);
                    $date = substr($buffer, $i+4, 7);

                    $dateId = $dates[$date];
                    $output[$path][$dateId]++;

                    $pos = $nextPos;
                    $nextPos = strpos($buffer, "\n", $nextPos+1);
                }
            }

            $left = substr($buffer, $pos+1);
            $read += $lenAsked;
        }

        return $this->convert($output, $dates, $order);
    }

    public function convert($input, $dates, $order) {
        $output = [];
        foreach($input as $key => $values) {
            foreach($dates as $date => $i) {
                if($values[$i]) {
                    $output[$key][$i] = $values[$i];
                }
            }
        }

        return [$output, array_keys($order)];
    }

    public function partParallel(string $inputPath, int $start, int $length, $paths, $dates) {
        list($readChannel, $writeChannel) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();

        if ($pid == 0) {
            fclose($readChannel);
            $output = $this->partParse($inputPath, $start, $length, $paths, $dates);
            fwrite($writeChannel, serialize($output));
            exit();
        }

        fclose($writeChannel);
        return [$pid, $readChannel];
    }

    public function partReadParallel($thread) {
        
        $output = "";
        while(!feof($thread[1])) {
            $output .= fread($thread[1], Parser::$READ_CHUNK);
        }

        pcntl_waitpid($thread[0], $status);

        $status;
        return unserialize($output);
    }

    public function parse(string $inputPath, string $outputPath): void
    {
        gc_disable();

        // Prepare arrays
        $dates = [];
        $dateCount = 0;
        for($y=0; $y!=6; $y++) {
            for($m=1; $m!=13; $m++) {
                for($d=1; $d!=32; $d++) {
                    $date = $y."-".str_pad($m, 2, "0", STR_PAD_LEFT)."-".str_pad($d, 2, "0", STR_PAD_LEFT);
                    $dates[$date] = $dateCount++;
                }
            }
        }
        for($m=1; $m!=3; $m++) {
            for($d=1; $d!=32; $d++) {
                $date = "6-".str_pad($m, 2, "0", STR_PAD_LEFT)."-".str_pad($d, 2, "0", STR_PAD_LEFT);
                $dates[$date] = $dateCount++;
            }
        }

        $paths = [];
        foreach(Visit::all() as $page) {
            $uri = substr($page->uri, 25);
            $paths[$uri] = array_fill(0, $dateCount, 0);
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
            $threads[$i] = $this->partParallel($inputPath, $ranges[$i][0], $ranges[$i][1]-$ranges[$i][0], $paths, $dates);
        }

        unset($paths);

        // Read threads
        $paths = [];
        $outputs = [];
        for($i=0; $i!=Parser::$CORES; $i++) {
            $output = $this->partReadParallel($threads[$i]);
            $outputs[$i] = $output[0];

            if($i==0) {
                $paths = $output[1];
            }
        }

        $paths = array_unique($paths);

        // Merge
        $merged = [];
        foreach($paths as $path) {
            $fullPath = "/blog/".$path;
            $merged[$fullPath] = [];

            foreach($dates as $date => $dateI) {
                $count = 0;
                for($i=0; $i!=Parser::$CORES; $i++) {
                    $count += $outputs[$i][$path][$dateI] ?? 0;
                }

                if($count != 0) {
                    $merged[$fullPath]["202".$date] = $count;
                }
            }
        }

        unset($outputs);
        file_put_contents($outputPath, json_encode($merged, JSON_PRETTY_PRINT));
    }
}