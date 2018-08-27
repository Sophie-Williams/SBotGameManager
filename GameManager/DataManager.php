<?php
namespace GameManager;
require_once 'Util.php';
/**
 * Class DataManager
 * @package FootballRMM
 * бд mysql
 * поля id, realname, wins, losses, wr
 */
class DataManager
{

    private $dbHost;

    private $dbUser;

    private $dbPass;

    private $db;

    private $table;

    public function __construct($dbHost, $dbUser, $dbPass, $db, $table = 'players')
    {
        $this->dbHost = $dbHost;
        $this->dbUser = $dbUser;
        $this->dbPass = $dbPass;
        $this->db = $db;
        $this->table = $table;
    }

    protected function connect()
    {
        $mysqli = new \mysqli($this->dbHost, $this->dbUser, $this->dbPass, $this->db);
        return $mysqli;
    }

    public function addWin($id)
    {
        $mysqli = $this->connect();
        if ($mysqli->connect_error) {
            $message="не удалось подключиться к бд";
            Util::log($message);
            die();
        } else {
            if ($player = $mysqli->query("SELECT wins, losses FROM $this->table WHERE id='$id'")->fetch_object()) {
                if (is_object($player)) {
                    $wins = $player->wins;
                    $losses = $player->losses;
                    $wins++;
                    $wr = round(($wins / ($wins + $losses)) * 100, 2);
                    $mysqli->query("UPDATE $this->table SET wins='$wins', wr='$wr' WHERE ID='$id'");
                }
            } else {
                $message="не удалось добавить победу игроку $id";
                Util::log($message);
            }
            $mysqli->close();
        }
    }

    public function addLose($id)
    {
        $mysqli = $this->connect();
        if ($mysqli->connect_error) {
            $message="не удалось подключиться к бд";
            Util::log($message);
            die();
        } else {
            if ($player = $mysqli->query("SELECT wins, losses FROM $this->table WHERE id='$id'")->fetch_object()) {
                if (is_object($player)) {
                    $wins = $player->wins;
                    $losses = $player->losses;
                    $losses++;
                    $wr = round(($wins / ($wins + $losses)) * 100, 2);
                    $mysqli->query("UPDATE $this->table SET losses='$losses',wr='$wr' WHERE ID='$id'");
                }
            } else {
                $message="не удалось добавить поражение игроку $id";
                Util::log($message);
            }
            $mysqli->close();
        }
    }

    public function checkIfPlayerExistInDB($id)
    {
        $mysqli = $this->connect();

        if ($mysqli->connect_error) {
            $message="не удалось подключиться к бд";
            Util::log($message);
            die();
        } else {
            $player = $mysqli->query("SELECT * FROM $this->table WHERE id='$id'")->fetch_object();
            if (is_object($player)) {
                $mysqli->close();
                return true;
            } else {
                $mysqli->close();
                return false;
            }
        }
    }

    public function insertNewPlayerIntoDB($id, $realname)
    {
        $mysqli = $this->connect();
        if ($mysqli->connect_error) {
            $message="не удалось подключиться к бд";
            Util::log($message);
            die();
        } else {
            $insert_row = $mysqli->query("INSERT INTO players (id,realname,wins,losses,wr) VALUES('$id','$realname',0,0,0)");
            if ($insert_row) {
                $message="добавили нового игрока $realname";
                Util::log($message);
            } else {
                $message="не смогли добавить нового игрока $id $realname";
                Util::log($message);
            }
        }
    }

    public function getPlayers()
    {
        $mysqli = $this->connect();
        $result=[];
        if ($mysqli->connect_error) {
            $message="не удалось подключиться к бд";
            Util::log($message);
            die();
        } else {
            $players = $mysqli->query("SELECT * FROM $this->table ORDER BY (`wins`+0) DESC");
            while($row = $players->fetch_object()) {
                $result[]=$row;
            }
            $mysqli->close();
            return $result;
        }
    }
    public function getPlayerById($id)
    {
        $mysqli = $this->connect();
        if ($mysqli->connect_error) {
            $message="не удалось подключиться к бд";
            Util::log($message);
            die();
        } else {
            $player = $mysqli->query("SELECT * FROM $this->table WHERE id='$id'")->fetch_object();
            if (is_object($player)) {
                $mysqli->close();
                return $player;
            } else {
                $mysqli->close();
                return false;
            }
        }
    }
}