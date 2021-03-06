<?php
namespace JakubOnderka\PhpParallelLint;

/*
Copyright (c) 2012, Jakub Onderka
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this
   list of conditions and the following disclaimer.
2. Redistributions in binary form must reproduce the above copyright notice,
   this list of conditions and the following disclaimer in the documentation
   and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

The views and conclusions contained in the software and documentation are those
of the authors and should not be interpreted as representing official policies,
either expressed or implied, of the FreeBSD Project.
 */

class Process
{
    const STDIN = 0,
        STDOUT = 1,
        STDERR = 2;

    const READ = 'r',
        WRITE = 'w';

    /** @var resource */
    protected $process;

    /** @var resource */
    protected $stdout;

    /** @var resource */
    protected $stderr;

    /** @var string */
    private $output;

    /** @var string */
    private $errorOutput;

    /** @var int */
    private $statusCode;

    /**
     * @param string $cmdLine
     * @throws \RuntimeException
     */
    public function __construct($cmdLine)
    {
        $descriptors = array(
            self::STDIN  => array('pipe', self::READ),
            self::STDOUT => array('pipe', self::WRITE),
            self::STDERR => array('pipe', self::WRITE),
        );

        $this->process = proc_open($cmdLine, $descriptors, $pipes, null, null, array('bypass_shell' => true));

        if ($this->process === false) {
            throw new \RuntimeException("Cannot create new process $cmdLine");
        }

        list($stdin, $this->stdout, $this->stderr) = $pipes;
        fclose($stdin);
    }

    /**
     * @return bool
     */
    public function isFinished()
    {
        if ($this->statusCode !== NULL) {
            return true;
        }

        $status = proc_get_status($this->process);

        if ($status['running']) {
            return false;
        } elseif ($this->statusCode === null) {
            $this->statusCode = (int) $status['exitcode'];
        }

        // Process outputs
        $this->output = stream_get_contents($this->stdout);
        fclose($this->stdout);

        $this->errorOutput = stream_get_contents($this->stderr);
        fclose($this->stderr);

        $statusCode = proc_close($this->process);

        if ($this->statusCode === null) {
            $this->statusCode = $statusCode;
        }

        $this->process = null;

        return true;
    }

    /**
     * @return string
     */
    public function getOutput()
    {
        if (!$this->isFinished()) {
            throw new \RuntimeException("Cannot get output for running process");
        }

        return $this->output;
    }

    /**
     * @return string
     */
    public function getErrorOutput()
    {
        if (!$this->isFinished()) {
            throw new \RuntimeException("Cannot get error output for running process");
        }

        return $this->errorOutput;
    }

    /**
     * @return int
     */
    public function getStatusCode()
    {
        if (!$this->isFinished()) {
            throw new \RuntimeException("Cannot get status code for running process");
        }

        return $this->statusCode;
    }

    /**
     * @return bool
     */
    public function isFail()
    {
        return $this->getStatusCode() === 1;
    }
}

class LintProcess extends Process
{
    /**
     * @param string $phpExecutable
     * @param string $fileToCheck Path to file to check
     * @param bool $aspTags
     * @param bool $shortTag
     */
    public function __construct($phpExecutable, $fileToCheck, $aspTags = false, $shortTag = false)
    {
        if (empty($phpExecutable)) {
            throw new \InvalidArgumentException("PHP executable must be set.");
        }
        if (empty($fileToCheck)) {
            throw new \InvalidArgumentException("File to check must be set.");
        }

        $cmdLine = escapeshellarg($phpExecutable);
        $cmdLine .= ' -d asp_tags=' . ($aspTags ? 'On' : 'Off');
        $cmdLine .= ' -d short_open_tag=' . ($shortTag ? 'On' : 'Off');
        $cmdLine .= ' -d error_reporting=E_ALL';
        $cmdLine .= ' -n -l ' . escapeshellarg($fileToCheck);

        parent::__construct($cmdLine);
    }

    /**
     * @return bool
     */
    public function hasSyntaxError()
    {
        return strpos($this->getOutput(), 'No syntax errors detected') === false;
    }

    /**
     * @return bool|string
     */
    public function getSyntaxError()
    {
        if ($this->hasSyntaxError()) {
            list(, $out) = explode("\n", $this->getOutput());
            return $out;
        }

        return false;
    }

    /**
     * @return bool
     */
    public function isFail()
    {
       return defined('PHP_WINDOWS_VERSION_MAJOR') ? $this->getStatusCode() === 1 : parent::isFail();
    }
}

class SkipLintProcess extends Process
{
    private $skipped = array();

    private $done = false;

    private $endLastChunk = '';

    public function __construct($phpExecutable, array $filesToCheck)
    {
        if (empty($phpExecutable)) {
            throw new \InvalidArgumentException("PHP executable must be set.");
        }

        $cmdLine = escapeshellarg($phpExecutable);
        $cmdLine .= ' -n ' . escapeshellarg(__DIR__ . '/../bin/skip-linting.php');
        $cmdLine .= ' ' . implode(' ', array_map('escapeshellarg', $filesToCheck));

        parent::__construct($cmdLine);
    }

    public function getChunk()
    {
        if (!$this->isFinished()) {
            $this->processLines(fread($this->stdout, 8192));
        }
    }

    public function isFinished()
    {
        $isFinished = parent::isFinished();
        if ($isFinished && !$this->done) {
            $this->done = true;
            $output = $this->getOutput();
            $this->processLines($output);
        }

        return $isFinished;
    }

    public function isSkipped($file)
    {
        if (isset($this->skipped[$file])) {
            return $this->skipped[$file];
        }

        return null;
    }

    private function processLines($content)
    {
        if (!empty($content)) {
            $lines = explode("\n", $this->endLastChunk . $content);
            $this->endLastChunk = array_pop($lines);
            foreach ($lines as $line) {
                $parts = explode(';', $line);
                list($file, $status) = $parts;
                $this->skipped[$file] = $status === '1' ? true : false;
            }
        }
    }
}
