<?php

namespace App;

use Exception;
use SplFileObject;
use App\Commands\Visit;

final class Parser
{
    static $READ_CHUNK = 500_000;
    static $CORES = 5;

    public function partParse(string $inputPath, int $start, int $length, $output, $dates) {
        $left = "";
        $read = 0;

        $file = new SplFileObject($inputPath);
        $file->setFlags(SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY);
        $file->fseek($start);

        $order = [];

        while (!$file->eof()) {
            $buffer = $left . $file->fread(Parser::$READ_CHUNK);

            $pos = -1;
            if($read == 0 && !str_starts_with($buffer, "https://")) {
                $pos = strpos($buffer, "\n");
            }

            $nextPos = strpos($buffer, "\n", $pos+1);
            while($nextPos !== false) {
                $i = strpos($buffer, ",", $pos+1);

                $path = substr($buffer, $pos+20, $i-$pos-20);
                $date = substr($buffer, $i+1, 10);

                $dateId = $dates[$date];
                $output[$path][$dateId]++;
                $order[$path] = true;

                $pos = $nextPos;
                $nextPos = strpos($buffer, "\n", $nextPos+1);

                if($read + $pos > $length) {
                    return $this->convert($output, $dates, $order);
                }
            }

            $left = "";
            if($pos !== false) {
                $left = substr($buffer, $pos+1);
            }

            $read += strlen($buffer) - strlen($left);
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
        for($y=2020; $y!=2026; $y++) {
            for($m=1; $m!=13; $m++) {
                for($d=1; $d!=32; $d++) {
                    $date = $y."-".str_pad($m, 2, "0", STR_PAD_LEFT)."-".str_pad($d, 2, "0", STR_PAD_LEFT);
                    $dates[$date] = $dateCount++;
                }
            }
        }
        for($m=1; $m!=3; $m++) {
            for($d=1; $d!=32; $d++) {
                $date = "2026-".str_pad($m, 2, "0", STR_PAD_LEFT)."-".str_pad($d, 2, "0", STR_PAD_LEFT);
                $dates[$date] = $dateCount++;
            }
        }

        $paths = [];
        foreach(Visit::all() as $page) {
            $uri = substr($page->uri, 19);
            $paths[$uri] = array_fill(0, $dateCount, 0);
        }

        // Start threads
        $length = ceil(filesize($inputPath)/Parser::$CORES);
        for($i=0; $i!=Parser::$CORES; $i++) {
            $threads[] = $this->partParallel($inputPath, $length*$i, $length, $paths, $dates);
        }

        // Read threads
        $paths = [];
        $outputs = [];
        for($i=0; $i!=Parser::$CORES; $i++) {
            $output = $this->partReadParallel($threads[$i]);
            $outputs[] = $output[0];

            $paths = array_merge($paths, $output[1]);
        }

        $paths = array_unique($paths);

        $merged = [];

        // Merge
        foreach($paths as $path) {
            $merged[$path] = [];

            foreach($dates as $date => $dateI) {
                $count = 0;
                for($i=0; $i!=Parser::$CORES; $i++) {
                    $count += $outputs[$i][$path][$dateI] ?? 0;
                }

                if($count != 0) {
                    $merged[$path][$date] = $count;
                }
            }
        }

        unset($outputs);
        file_put_contents($outputPath, json_encode($merged, JSON_PRETTY_PRINT));
    }
}