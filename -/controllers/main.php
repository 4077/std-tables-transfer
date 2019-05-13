<?php namespace std\tablesTransfer\controllers;

class Main extends \Controller
{
    private $sourceEnv;

    private $targetEnv;

    public function __create()
    {
        if ($direction = $this->data('direction')) {
            if ($envs = $this->parseDirection($direction)) {
                list($sourceEnv, $targetEnv) = $envs;

                $this->sourceEnv = $sourceEnv;
                $this->targetEnv = $targetEnv;
            }
        } else {
            $sourceEnvName = $this->data('source'); // todo [app:]env
            $targetEnvName = $this->data('target'); // todo [app:]env

            if ($sourceEnv = \ewma\apps\models\Env::where('app_id', 0)->where('name', $sourceEnvName)->first()) {
                $this->sourceEnv = $sourceEnv;
            }

            if ($targetEnv = \ewma\apps\models\Env::where('app_id', 0)->where('name', $targetEnvName)->first()) {
                $this->targetEnv = $targetEnv;
            }
        }

        if (null === $this->sourceEnv) {
            $this->lock('not defined source');
        }

        if (null === $this->targetEnv) {
            $this->lock('not defined target');
        }
    }

    private function parseDirection($direction)
    {
        $exploded = explode('2', $direction);

        if (count($exploded) == 2) {
            $sourceEnvShortName = $exploded[0];
            $targetEnvShortName = $exploded[1];

            $sourceEnv = \ewma\apps\models\Env::where('app_id', 0)->where('short_name', $sourceEnvShortName)->first();
            $targetEnv = \ewma\apps\models\Env::where('app_id', 0)->where('short_name', $targetEnvShortName)->first();

            if ($sourceEnv && $targetEnv) {
                return [$sourceEnv, $targetEnv];
            }
        }
    }

    public function run()
    {
        start_time($this->_nodeId());

        $sourceEnv = $this->sourceEnv;
        $targetEnv = $this->targetEnv;

        $tables = l2a($this->data('tables'));
        diff($tables, '');

        $sourceRemote = remote($sourceEnv->name);
        $targetRemote = remote($targetEnv->name);

        if ($sourceRemote && $targetRemote && count($tables)) {
            $this->log($sourceEnv->name . ' -> ' . $targetEnv->name . ': ' . a2l($tables));

            $exportCommand = $this->getExportCommand($sourceRemote, $this->data('source_database') ?: 'default');
            $importCommand = $this->getImportCommand($targetRemote, $this->data('target_database') ?: 'default');

            $currentEnv = \ewma\apps\models\Env::where('app_id', 0)->where('name', $this->_env())->first();
            $currentServer = $currentEnv->server;

            $sourceServer = $sourceEnv->server;
            $targetServer = $targetEnv->server;

            $errors = [];

            if ($sourceServer != $currentServer) {
                if ($sshConnection = ewmas()->getSshConnectionString($sourceServer)) {
                    $exportCommand = 'ssh ' . $sshConnection . ' ' . $exportCommand;
                } else {
                    $errors[] = 'has not ssh connection to source server';
                }
            }

            if ($targetServer != $currentServer) {
                if ($sshConnection = ewmas()->getSshConnectionString($targetServer)) {
                    $importCommand = 'ssh ' . $sshConnection . ' ' . $importCommand;
                } else {
                    $errors[] = 'has not ssh connection to target server';
                }
            }

            $result = [];

            if (!$errors) {
                $command = $exportCommand . ' | ' . $importCommand;

                if ($command) {
                    exec($command, $result);
                }
            }

            return [
                'errors'   => $errors,
                'source'   => $sourceEnv,
                'target'   => $targetEnv,
                'command'  => $command,
                'duration' => end_time($this->_nodeId(), true),
                'result'   => $result
            ];
        }
    }

    private function getExportCommand(\ewma\remoteCall\Remote $remote, $database)
    {
        $dbConfig = $remote->call('/ -:_appConfig:databases/' . $database);

        return 'mysqldump -u ' . $dbConfig['user'] . ' -p' . $dbConfig['pass'] . ' ' . $dbConfig['name'] . ' ' . implode(' ', l2a($this->data('tables')));
    }

    private function getImportCommand(\ewma\remoteCall\Remote $remote, $database)
    {
        $dbConfig = $remote->call('/ -:_appConfig:databases/' . $database);

        return 'mysql -u ' . $dbConfig['user'] . ' -p' . $dbConfig['pass'] . ' ' . $dbConfig['name'];
    }
}
