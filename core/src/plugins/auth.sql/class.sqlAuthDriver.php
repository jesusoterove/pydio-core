<?php
/*
 * Copyright 2007-2013 Charles du Jeu - Abstrium SAS <team (at) pyd.io>
 * This file is part of Pydio.
 *
 * Pydio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Pydio is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Pydio.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://pyd.io/>.
 */
defined('AJXP_EXEC') or die( 'Access not allowed');

/**
 * Store authentication data in an SQL database
 * @package AjaXplorer_Plugins
 * @subpackage Auth
 */
class sqlAuthDriver extends AbstractAuthDriver
{
    public $sqlDriver;
    public $driverName = "sql";

    public function init($options)
    {
        parent::init($options);
        require_once(AJXP_BIN_FOLDER."/dibi.compact.php");
        $this->sqlDriver = AJXP_Utils::cleanDibiDriverParameters($options["SQL_DRIVER"]);
        try {
            dibi::connect($this->sqlDriver);
        } catch (DibiException $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
            exit(1);
        }
    }

    public function performChecks()
    {
        if(!isSet($this->options)) return;
        $test = AJXP_Utils::cleanDibiDriverParameters($this->options["SQL_DRIVER"]);
        if (!count($test)) {
            throw new Exception("You probably did something wrong! To fix this issue you have to remove the file \"bootsrap.json\" and rename the backup file \"bootstrap.json.bak\" into \"bootsrap.json\" in data/plugins/boot.conf/");
        }
    }

    public function supportsUsersPagination()
    {
        return true;
    }
    public function listUsersPaginated($baseGroup = "/", $regexp, $offset, $limit)
    {
        if ($regexp != null) {
            if($regexp[0]=="^") $regexp = ltrim($regexp, "^")."%";
            else if($regexp[strlen($regexp)-1] == "$") $regexp = "%".rtrim($regexp, "$");
            $res = dibi::query("SELECT * FROM [ajxp_users] WHERE [login] LIKE '".$regexp."' AND [groupPath] LIKE %s ORDER BY [login] ASC", $baseGroup."%") ;
        } else if ($offset != -1 || $limit != -1) {
            $res = dibi::query("SELECT * FROM [ajxp_users] WHERE [groupPath] LIKE %s ORDER BY [login] ASC %lmt %ofs", $baseGroup."%", $limit, $offset);
        } else {
            $res = dibi::query("SELECT * FROM [ajxp_users] WHERE [groupPath] LIKE %s ORDER BY [login] ASC", $baseGroup."%");
        }
        $pairs = $res->fetchPairs('login', 'password');
           return $pairs;
    }
    public function getUsersCount($baseGroup = "/", $regexp = "")
    {
        if (!empty($regexp)) {
            if($regexp[0]=="^") $regexp = ltrim($regexp, "^")."%";
            else if($regexp[strlen($regexp)-1] == "$") $regexp = "%".rtrim($regexp, "$");
            $res = dibi::query("SELECT [login] FROM [ajxp_users] WHERE [login] LIKE '".$regexp."' AND [groupPath] LIKE %s ", $baseGroup."%") ;
        } else {
            $res = dibi::query("SELECT [login] FROM [ajxp_users] WHERE [groupPath] LIKE %s", $baseGroup."%");
        }
        return count($res->fetchAll());
    }

    public function listUsers($baseGroup="/")
    {
        $pairs = array();
        $res = dibi::query("SELECT * FROM [ajxp_users] WHERE [groupPath] LIKE %s ORDER BY [login] ASC", $baseGroup."%");
        $rows = $res->fetchAll();
        foreach ($rows as $row) {
            $grp = $row["groupPath"];
            if(strlen($grp) > strlen($baseGroup)) continue;
            $pairs[$row["login"]] = $row["password"];
        }
        return $pairs;
    }

    public function userExists($login)
    {
        $res = dibi::query("SELECT * FROM [ajxp_users] WHERE [login]=%s", $login);
        return(count($res->fetchAll()) > 0);
    }

    public function checkPassword($login, $pass, $seed)
    {
        $userStoredPass = $this->getUserPass($login);
        if(!$userStoredPass) return false;

        if ($this->getOption("TRANSMIT_CLEAR_PASS") === true) { // Seed = -1 means that password is not encoded.
            return AJXP_Utils::pbkdf2_validate_password($pass, $userStoredPass); //($userStoredPass == md5($pass));
        } else {
            return (md5($userStoredPass.$seed) == $pass);
        }
    }

    public function usersEditable()
    {
        return true;
    }
    public function passwordsEditable()
    {
        return true;
    }

    public function createUser($login, $passwd)
    {
        if($this->userExists($login)) return "exists";
        $userData = array("login" => $login);
        if ($this->getOption("TRANSMIT_CLEAR_PASS") === true) {
            $userData["password"] = AJXP_Utils::pbkdf2_create_hash($passwd); //md5($passwd);
        } else {
            $userData["password"] = $passwd;
        }
        $userData['groupPath'] = '/';
        dibi::query('INSERT INTO [ajxp_users]', $userData);
    }
    public function changePassword($login, $newPass)
    {
        if(!$this->userExists($login)) throw new Exception("User does not exists!");
        $userData = array("login" => $login);
        if ($this->getOption("TRANSMIT_CLEAR_PASS") === true) {
            $userData["password"] = AJXP_Utils::pbkdf2_create_hash($newPass); //md5($newPass);
        } else {
            $userData["password"] = $newPass;
        }
        dibi::query("UPDATE [ajxp_users] SET ", $userData, "WHERE [login]=%s", $login);
    }
    public function deleteUser($login)
    {
        dibi::query("DELETE FROM [ajxp_users] WHERE [login]=%s", $login);
    }

    public function getUserPass($login)
    {
        $res = dibi::query("SELECT [password] FROM [ajxp_users] WHERE [login]=%s", $login);
        $pass = $res->fetchSingle();
        return $pass;
    }

    public function installSQLTables($param)
    {
        $p = AJXP_Utils::cleanDibiDriverParameters($param["SQL_DRIVER"]);
        return AJXP_Utils::runCreateTablesQuery($p, $this->getBaseDir()."/create.sql");
    }

}
