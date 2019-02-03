<?php namespace std\tablesTransfer\controllers;

class Main extends \Controller
{
    public function run()
    {
        start_time($this->_nodeId());

        $source = $this->data('source');
        $target = $this->data('target');

        $sourceServer = remote($source);
        $targetServer = remote($target);

        if ($sourceServer && $targetServer) {
            $exportCommand = $this->getExportCommand($sourceServer, $this->data('source_database') ?: 'default');
            $importCommand = $this->getImportCommand($targetServer, $this->data('target_database') ?: 'default');

            $sshConfig = dataSets()->get('config/ssh');

            $ssh = ap($sshConfig[$source], $target);

            $command = false;

            if ($ssh === true) {
                $command = $exportCommand . ' | ' . $importCommand;
            } else {
                if ($ssh) {
                    if ($this->_env($source)) {
                        $command = $exportCommand . ' | ssh ' . $ssh . ' ' . $importCommand;
                    }

                    if ($this->_env($target)) {
                        $command = 'ssh ' . $ssh . ' ' . $exportCommand . ' | ' . $importCommand;
                    }
                } else {
                    $ssh = ap($sshConfig[$target], $source);

                    if ($ssh) {
                        $command = 'ssh ' . $ssh . ' ' . $exportCommand . ' | ' . $importCommand;
                    }
                }
            }

            $result = [];

            if ($command) {
                exec($command, $result);
            }

            return [
                'source'   => $source,
                'target'   => $target,
                'command'  => $command,
                'duration' => end_time($this->_nodeId(), true),
                'result'   => $result
            ];
        }
    }

    private function getExportCommand(\ewma\remoteCall\Remote $server, $database)
    {
        $dbConfig = $server->call('/ -:_appConfig:databases/' . $database);

        return 'mysqldump -u ' . $dbConfig['user'] . ' -p' . $dbConfig['pass'] . ' ' . $dbConfig['name'] . ' ' . implode(' ', l2a($this->data('tables')));
    }

    private function getImportCommand(\ewma\remoteCall\Remote $server, $database)
    {
        $dbConfig = $server->call('/ -:_appConfig:databases/' . $database);

        return 'mysql -u ' . $dbConfig['user'] . ' -p' . $dbConfig['pass'] . ' ' . $dbConfig['name'];
    }
}
