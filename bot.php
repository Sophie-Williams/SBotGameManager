<?php

use GameManager\DataManager;

require_once 'GameManager/DataManager.php';
require_once 'vendor/autoload.php';

class Bot
{

    private $client;

    private $loop;

    private $slackApiClient;

    private $dataManager;

    private $status;

    private $amountOfPlayers;

    private $amountOfWinners;

    private $winners;

    private $players;

    private $mesColors;

    private $channel;

    public function __construct()
    {
        $this->loop = React\EventLoop\Factory::create();
        $config = parse_ini_file('config.ini');

        //обертки для слака, все нужны, все важны
        $this->slackApiClient = new wrapi\slack\slack($config['token']);
        $this->client = new Slack\RealTimeClient($this->loop);
        $this->client->setToken($config['token']);
        $this->client->connect();
        $this->channel = '';

        //бд
        $this->dataManager = new  DataManager($config['db_host'], $config['db_user'], $config['db_pass'],
            $config['db_name']);

        //набор игры
        $this->players = [];
        $this->status = "false";
        $this->amountOfPlayers = 0;
        $this->amountOfWinners = 0;
        $this->winners = [];

        //оформление
        $this->mesColors["red"] = "#a63639";
        $this->mesColors["green"] = "#36a64f";
        $this->mesColors["win"] = "#ffba00";
        $this->mesColors["grey"] = "#c4c4c4";

    }

    public function init()
    {
        $client = $this->client;
        $slackApiClient = $this->slackApiClient;

        $client->on('reaction_added', function ($data) {
            if (stripos($this->channel, 'D') !== 0 && $this->status == 'onair') {
                $this->selectWinnersLosers($data);
            }
        });

        $client->on('message', function ($data) use ($slackApiClient) {
            if ($this->channel != $data['channel']) {
                $this->channel = $data['channel'];
            }
            /*
             * C, it's a public channel
             * D, it's a DM with the user
             * G, it's either a private channel or multi-person DM
             */
            if (stripos($this->channel, 'D') !== 0) {
                if (stripos($data['text'], '+') === 0) {
                    $this->addPlayersToGame($data);
                }
                if (stripos($data['text'], '!match') === 0) {
                    $this->startGame($data);
                }
                if ($data['text'] == "!cancel") {
                    $user = $slackApiClient->users->info(["user" => $data['user']]);
                    $this->cancelGame('', '@'.$user['user']['name']);
                }
            }
            if ($data['text'] == "!rating") {
                $this->showRating();
            }
            if ($data['text'] == "!info") {
                $message = ":fire: *!match(2,4)* - начать игру\n";
                $message .= ":fire: *+* - вступить в игру\n";
                $message .= ":fire: *!cancel* - отменить игру\n";
                $message .= ":fire: *когда игра заканчивается, ставим* :muscle: *под комменты победителей*\n";
                $message .= ":fire: *!rating* - статка по игрокам, сортировка по кол-ву игр\n";
                $message .= ":fire: *!rating @name* - статка по игроку\n";
                $message .= ":fire: https://github.com/SergeyGauert/SBotGameManager\n";
                $this->postMessage($message, $this->mesColors['grey']);
            }
            if (stripos($data['text'], '!rating <@') === 0) {
                if (preg_match('/<@(.*?)>/', $data['text'], $match) == 1) {
                    $this->showRatingByUser($match[1]);
                }
            }
        });
        $this->loop->run();
    }

    public function showRating()
    {
        $dataManager = $this->dataManager;
        $players = $dataManager->getPlayers();
        $kColor = 80 / count($players);
        foreach ($players as $key => $player) {
            $red = intval(80 - (80 - $kColor * $key));
            $green = intval(80 - $kColor * $key);
            if ($red < 10) {
                $red = "0".$red;
            }
            if ($green < 10) {
                $green = "0".$green;
            }
            $color = "$red"."$green"."00";
            $message = "*$player->realname* \n W:$player->wins L:$player->losses WR:$player->wr%";
            $this->postMessage($message, $color);
        }
    }

    public function showRatingByUser($id)
    {
        $client = $this->client;
        $dataManager = $this->dataManager;
        $player = $dataManager->getPlayerById($id);
        if ($player) {
            $message = "*$player->realname* \n W:$player->wins L:$player->losses WR:$player->wr%";
        } else {
            $message = "Информации по этому игроку не найдено, скорее всего таких у нас еще не играло.";
        }
        $this->postMessage($message, $this->mesColors['grey']);
    }

    public function addPlayersToGame($data)
    {
        $client = $this->client;
        $slackApiClient = $this->slackApiClient;
        $dataManager = $this->dataManager;

        switch ($this->status) {
            case "onair":
                $user = $slackApiClient->users->info(["user" => $data['user']]);
                $message = "@".$user["user"]["name"].", извини, драка уже началась";
                $this->postMessage($message, $this->mesColors['red']);
                break;
            case "waiting":
                if (!in_array($data['user'], $this->players)) {
                    $this->players[] = $data['user'];
                    $user = $slackApiClient->users->info(["user" => $data['user']]);
                    if (!$dataManager->checkIfPlayerExistInDB($data['user'])) {
                        $dataManager->insertNewPlayerIntoDB($data['user'], $user["user"]["real_name"]);
                    }
                    $this->amountOfPlayers--;
                    if ($this->amountOfPlayers > 0) {
                        $this->status = "waiting";
                        $message = "@".$user["user"]["name"]." вступает в игру!\n ";
                        $this->postMessage($message, $this->mesColors['green']);

                        $message = "Ждем еще *$this->amountOfPlayers*!";
                        $this->postMessage($message, $this->mesColors['grey']);
                    } else {
                        $this->status = "onair";
                        $message = "@".$user["user"]["name"]." вступает в игру!\n ";
                        $this->postMessage($message, $this->mesColors['green']);

                        $message = "Да начнется битва!";
                        $this->postMessage($message, $this->mesColors['win']);
                    }
                } else {
                    $user = $slackApiClient->users->info(["user" => $data['user']]);
                    $message = "@".$user["user"]["name"]." вы уже в танцах, все ок :wink:";
                    $this->postMessage($message, $this->mesColors['grey']);
                }
                break;
        }
    }

    public function startGame($data)
    {
        $slackApiClient = $this->slackApiClient;
        $dataManager = $this->dataManager;

        if ($this->status != "waiting" && $this->status != "onair") {
            $msg = explode('h', $data['text']);
            switch ($msg[1]) {
                case 2:
                    $this->amountOfPlayers = "2";
                    $this->status = "waiting";
                    break;
                case 4:
                    $this->amountOfPlayers = "4";
                    $this->status = "waiting";
                    break;
                default;
                    $this->amountOfPlayers = " укажите кол-во игроков (2 или 4)!";
                    $this->status = "false";
                    break;
            }
            switch ($this->status) {
                case "false":
                    $user = $slackApiClient->users->info(["user" => $data['user']]);
                    $message = "@".$user['user']['name'].$this->amountOfPlayers;
                    $this->postMessage($message, $this->mesColors['red']);
                    break;
                case "waiting":
                    $this->players[] = $data['user'];
                    $this->amountOfWinners = $this->amountOfPlayers / 2;
                    $user = $slackApiClient->users->info(["user" => $data['user']]);
                    if (!$dataManager->checkIfPlayerExistInDB($data['user'])) {
                        $dataManager->insertNewPlayerIntoDB($data['user'], $user["user"]["real_name"]);
                    }
                    $message = ":fire::fire::fire: "."@".$user["user"]["name"]." собирает игру на *$this->amountOfPlayers* игроков!";
                    $this->postMessage($message, $this->mesColors['green']);
                    $this->amountOfPlayers--;
                    $message = "Ждем еще *$this->amountOfPlayers*!";
                    $this->postMessage($message, $this->mesColors['grey']);
                    break;
            }
        } else {
            $user = $slackApiClient->users->info(["user" => $data['user']]);
            $message = "@".$user["user"]["name"].", извини, игра уже ";
            $message .= ($this->status == "waiting") ? "собирается" : "идет";
            $this->postMessage($message, $this->mesColors['red']);
        }
    }

    public function cancelGame($why = '', $byWho = '')
    {
        if ($this->status == "waiting" || $this->status == "onair") {
            $this->players = [];
            $this->winners = [];
            $this->status = "false";
            $this->amountOfPlayers = 0;
            $this->amountOfWinners = 0;

            $message = "Игра была отменена ".$why.$byWho." \n:pensive:";
            $this->postMessage($message, $this->mesColors["grey"]);
        } else {
            $message = "Игры так то и нет, но привет ".$byWho." \n:wink:";
            $this->postMessage($message, $this->mesColors["grey"]);
        }
    }

    public function selectWinnersLosers($data)
    {
        $slackApiClient = $this->slackApiClient;
        $dataManager = $this->dataManager;
        if ($this->amountOfWinners > 0) {
            if ($data['reaction'] == 'muscle') {
                if (in_array($data['item_user'], $this->players)) {
                    $dataManager->addWin($data['item_user']);
                    $this->winners[] = $data['item_user'];
                    unset($this->players[array_search($data['item_user'], $this->players)]);
                    $this->amountOfWinners--;
                }
            }
        }
        if ($this->amountOfWinners == 0 && $this->status == 'onair') {
            foreach ($this->players as $key => $player) {
                $dataManager->addLose($player);
            }
            $message = "Отличная игра!\n";
            $message .= "В этот раз победа за ";
            foreach ($this->winners as $key => $winner) {
                $user = $slackApiClient->users->info(["user" => $winner]);
                $message .= "@".$user["user"]["name"]." ";
            }

            $this->players = [];
            $this->winners = [];
            $this->status = "false";
            $this->amountOfPlayers = 0;
            $this->amountOfWinners = 0;

            $message .= "\n:fire::fire::fire::fire::fire:";
            $this->postMessage($message, $this->mesColors['win']);
        }
    }

    public function postMessage($message, $color)
    {
        $slackApiClient = $this->slackApiClient;
        $slackApiClient->chat->postMessage([
            "channel" => $this->channel,
            "parse" => "full",
            "link_names" => 1,
            "mrkdwn" => true,
            "as_user" => true,
            "attachments" => '[{"color": "'.$color.'", "text": "'.$message.'"}]'
        ]);
    }
}

$slackClient = new Bot();
$slackClient->init();