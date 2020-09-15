<?php

class Manager
{
    private $configs;
    private $last;
    private $i18n;

    /**
     * Loads configs and check files
     */
    public function __construct()
    {
        //Load configs
        $this->loadConfigs();

        //Check last files
        $this->checkFileLast();
    }

    /**
     * Loads configs from Config.php
     */
    private function loadConfigs()
    {
        $this->configs = Config::getConfigs();
    }

    /**
     * Checks last.json file, if error - close bot
     */
    private function checkFileLast()
    {
        //Check if file exists
        if (!file_exists(Config::getFileLast())) {

            //Create new file with default name
            $content = [
                $this->configs[0]["t_name"] . "_" . $this->configs[0]["vk"] => -1
            ];
            $file = json_encode($content);
            file_put_contents(Config::getFileLast(), $file);
        }

        //Load file content
        $last = json_decode(file_get_contents(Config::getFileLast()), true);

        //Check if we have some troubles, while reading from last.json
        if (empty($last)) {
            Log::addLog("For some reason " . Config::getFileLast() . " is empty or we can't properly read from it");

            //Close bot manager
            $this->close();
        }

        //If okay write last posts to object
        $this->last = $last;
    }

    /**
     * Starts bot and run every config
     */
    public function start()
    {
        //create i18n instance
        $this->i18n = new I18N();

        //Run every config
        foreach ($this->configs as $configIndex => $config) {
            //Load language for this config
            $this->i18n->loadLang($config["language"]);

            //Get VK response
            $response = $this->getVk($config["vk"], $config["vk_token"]);

            //If we have good response
            if ($response) {

                //Create Telegram API
                $telegram = new TelegramApi($config["t_key"], $config["t_name"], $config["t_chat"]);

                //Load last.json
                $key_save = $config["t_chat"] . "_" . $config["vk"];
                if (isset($this->last[$key_save])) {
                    $last = $this->last[$key_save];
                } else {
                    $last = [-1];
                }

                //Send messages
                $posted = $this->send($response, $telegram, $last, $config, $configIndex);

                //Save log
                if ($posted["counter"] > 0) {
                    $log = "Add " . $posted["counter"] . " new posts: " . implode(",", $posted["ids"]) . " | from " . Config::getFileLast() . ": " . implode(",", $last);
                    Log::addLog($log);

                    //Update last
                    $posts = array_merge($last, $posted["ids"]);
                    $this->last[$key_save] = $posts;
                }
            }
        }

        //Save updated posts
        $this->savePosts();
    }

    /**
     * @param $vk_id string - id of vk user/group
     * @param $vk_token string - vk service token
     * @return bool|mixed - return false if failed to load vk, return vk response if ok
     * Loads VK, if have errors - log them
     */
    private function getVk($vk_id, $vk_token)
    {
        //Get vk response
        $vk_response = VkApi::request(VkApi::getMethodUrl("wall.get", Config::getVkParams($vk_id, $vk_token)));
        $response = $vk_response["response"];

        //Check if we have no posts
        if (empty($response["items"])) {
            Log::addLog("Fail loading data from VK: " . $vk_id . " More info: " . json_encode($vk_response));
            return false;
        }

        return $response;
    }

    /**
     * @param $config array - config for current entity
     * @return array - return $infoAboutVKSource with ["name"] and ["url"]
     * Parse info about original VK source object(name, url)
     */
    private function getInfoAboutVkObjectById($config)
    {
        $vk_id = $config["vk"];
        $vk_token = $config["vk_token"];

        //Check if it is a group or a person
        $isGroup = ($vk_id[0] === "-");
        $url = "https://vk.com/" . ($isGroup ? "public" . substr($vk_id, 1) : "id" . $vk_id);

        //Check if we need to parse real user/public name from VK
        if (isset($config["extended"]["needFromText"]["customName"])) {
            $name = $config["extended"]["needFromText"]["customName"];
        } else {
            //Get vk response
            $vk_params = Config::getVkParams($vk_id, $vk_token);
            $isGroup ? $vk_params["group_ids"] = substr($vk_id, 1) : $vk_params["user_ids"] = $vk_id;
            $vk_response = VkApi::request(VkApi::getMethodUrl($isGroup ? "groups.getById" : "users.get", $vk_params));
            $response = $vk_response["response"][0];
            $name = $isGroup ? $response["name"] : $response["first_name"] . " " . $response["last_name"];
        }

        return [
            "name" => $name,
            "url" => $url
        ];
    }

    /**
     * @param $response - response from VK
     * @param $telegram - telegram API object
     * @param $last - last posted ids
     * @param $config - config for this entity
     * @param $configIndex - index of current $config
     * @return array - $posted object(counter + posted ids array)
     * Sends messages to Telegram, if have new posts
     */
    private function send($response, $telegram, $last, $config, $configIndex)
    {
        //Preload info about VK source
        if (isset($config["extended"]["needFromText"]) && $config["extended"]["needFromText"]) {
            $infoAboutVKSource = self::getInfoAboutVkObjectById($config);
        }

        //Check posts
        $key = count($response["items"]) - 1;
        $posted = [
            "counter" => 0,
            "ids" => []
        ];
        while ($key >= 0) {
            $post = $response["items"][$key];

            //If we have matches or post[id] equals 0 or -1(vk api bad responses) => ignore them
            if (!in_array($post["id"], $last) || $post["id"] == 0 || $post["id"] == -1) {

                $message = "https://vk.com/wall" . $config["vk"] . "_" . $post["id"];

                //   TODO optimize this
                $postText = false;
                if (isset($post["text"])) {
                    $postText = VkLinksParser::parseInternalLinks($post["text"], $configIndex);
                }

                //Set sendMessage parameters
                $messageParams['disable_web_page_preview'] = isset($config["messageSend"]["disable_web_page_preview"]) ? $config["messageSend"]["disable_web_page_preview"] : false;
                $messageParams['disable_notification'] = isset($config["messageSend"]["disable_notification"]) ? $config["messageSend"]["disable_notification"] : false;
                $messageParams['parse_mode'] = isset($config["messageSend"]["parse_mode"]) ? $config["messageSend"]["parse_mode"] : 'HTML';

                //Check what type of posting we need
                if (isset($config["extended"]["active"]) && $config["extended"]["active"]) {

                    //If we have text in VK post - send it to Telegram
                    if ($postText) {

                        $message = $postText;

                        //If we need to append link to original VK post
                        if (isset($config["extended"]["needLinkToVKPost"]) && $config["extended"]["needLinkToVKPost"]) {
                            $message = TextManager::appendLinkToVKPost($postText, $message, $this->i18n, "true");
                        }

                        //If we need to add link to original VK group
                        if (isset($config["extended"]["needFromText"]) && $config["extended"]["needFromText"]) {
                            //If we need to add text about original VK Group
                            $infoAboutVKSource["withLink"] = (isset($config["extended"]["needFromText"]["withLink"]) && $config["extended"]["needFromText"]["withLink"]);
                            $message = TextManager::addFromText($postText, $infoAboutVKSource, $this->i18n, (isset($config["extended"]["needFromText"]["prepend"]) && $config["extended"]["needFromText"]["prepend"]));
                        }

                        //Send message
                        //$telegram->sendMessage($message, $messageParams);
                    }


                    //If we have attachments - check them
                    if (isset($post["attachments"]) && $config["extended"]["resendAttachments"]) {

                        //Scan all attachments for photos
                        foreach ($post["attachments"] as $attach) {
                            if ($attach["type"] == "photo") {
                                $telegram->sendPhoto(VkApi::findMaxSizeLink($attach["photo"]));
                            } elseif ($attach["type"] == "link" && isset($attach["link"]["photo"])) {
                                $telegram->sendPhoto(VkApi::findMaxSizeLink($attach["link"]["photo"]));
                            } elseif ($attach["type"] == "doc" && isset($attach["doc"]["preview"]["video"])) {
                                $telegram->sendGIF($attach["doc"]["preview"]["video"]['src']);
                            }
                        }
                    }
                } else {

                    //Check if need to append post preview
                    if (isset($config["needPostPreview"]) && $config["needPostPreview"]) {

                        //If we have post text - send it
                        if ($postText) {
                            $message = TextManager::getTextPreview($postText, $message, $configIndex, $this->i18n);
                        }
                    }

                    //Send message
                    $telegram->sendMessage($message, $messageParams);
                }

                //Increase posted counter
                $posted["counter"]++;

                //Save posted id
                array_push($posted["ids"], $post["id"]);
            }

            $key--;
        }

        //Return posted info
        return $posted;
    }

    /**
     * Saves last posted ids to last.json
     */
    private function savePosts()
    {
        Log::saveLast($this->last);
    }

    /**
     * Closes the script
     */
    private function close()
    {
        die();
    }
}