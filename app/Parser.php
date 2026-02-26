<?php

namespace App;

use Exception;
use SplFileObject;

final class Parser
{
    static $READ_CHUNK = 500_000;
    static $CORES = 4;
    static $PREFILL_DATES = 3000;

    public function partParse(string $inputPath, int $start, int $length) {
        $output = [];

        $dates = [];
        $dateCount = 0;

        $left = "";
        $read = 0;

        $file = new SplFileObject($inputPath);
        $file->setFlags(SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY);
        $file->fseek($start);

        while (!$file->eof()) {
            $buffer = $left . $file->fread(Parser::$READ_CHUNK);

            $pos = -1;
            if($read == 0 && !str_starts_with($buffer, "https://")) {
                $pos = strpos($buffer, "\n");
                $read -= $pos;
            }

            $nextPos = strpos($buffer, "\n", $pos+1);
            while($nextPos !== false) {
                $i = strpos($buffer, ",", $pos+1);

                $path = substr($buffer, $pos+20, $i-$pos-20);
                $date = substr($buffer, $i+1, 10);

                if(!isset($dates[$date])) {
                    $dates[$date] = $dateCount;
                    $dateCount++;
                }

                $dateId = $dates[$date];
                if (!isset($output[$path])) {
                    $output[$path] = array_fill(0, Parser::$PREFILL_DATES, 0);
                }

                $output[$path][$dateId]++;

                $pos = $nextPos;
                $nextPos = strpos($buffer, "\n", $nextPos+1);

                if($read + $pos > $length) {
                    return $this->convert($output, $dates);
                }
            }

            $read += Parser::$READ_CHUNK;

            $left = "";
            if($pos !== false) {
                $left = substr($buffer, $pos+1);
            }
        }

        return $this->convert($output, $dates);
    }

    public function convert($input, $dates) {
        $output = [];
        foreach($input as $key => $values) {
            foreach($dates as $date => $i) {
                if($values[$i]) {
                    $output[$key][$date] = $values[$i];
                }
            }
        }

        return $output;
    }

    public function partParallel(string $inputPath, int $start, int $length) {
        list($readChannel, $writeChannel) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();

        if ($pid == 0) {
            fclose($readChannel);
            $output = $this->partParse($inputPath, $start, $length);
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
        // Start threads
        $length = ceil(filesize($inputPath)/Parser::$CORES);
        for($i=0; $i!=Parser::$CORES; $i++) {
            $threads[] = $this->partParallel($inputPath, $length*$i, $length);
        }

        // Read threads
        $outputs = [];
        $paths = [];
        for($i=0; $i!=Parser::$CORES; $i++) {
            $output = $this->partReadParallel($threads[$i]);
            $outputs[] = $output;
            $paths = array_merge($paths, array_keys($output));
        }
        $paths = array_unique($paths);

        $merged = [];

        // Merge
        foreach($paths as $path) {
            $merged[$path] = [];

            $dates = [];
            for($i=0; $i!=Parser::$CORES; $i++) {
                $dates = array_merge($dates, array_keys($outputs[$i][$path] ?? []));
            }
            $dates = array_unique($dates);
            sort($dates);

            foreach($dates as $date) {
                $count = 0;
                for($i=0; $i!=Parser::$CORES; $i++) {
                    $count += $outputs[$i][$path][$date] ?? 0;
                }

                if($count != 0) {
                    $merged[$path][$date] = $count;
                }
            }
        }

        file_put_contents($outputPath, json_encode($merged, JSON_PRETTY_PRINT));
    }
}