<?php
$config = [
    'group_token' => 'bot group token',
    'group_id' => 'bot group id',
    'admin_ids' => ['bot admins id'],
    'moderator_ids' => [],
    'log_dir' => __DIR__ . '/logs/',
    'data_dir' => __DIR__ . '/data',
    'mute_file' => __DIR__ . '/mute_list.json',
    'kick_file' => __DIR__ . '/kick_list.json',
    'ban_file' => __DIR__ . '/ban_list.json',
    'stats_file' => __DIR__ . '/stats.json',
    'nicknames_file' => __DIR__ . '/nicknames.json',
    'warn_file' => __DIR__ . '/warn_list.json',
    'unified_chats_file' => __DIR__ . '/unified_chats.json',
    'unified_mode' => true,
    'moderation' => [
        'flood_control' => [
            'max_messages' => 5,
            'time_window' => 10,
            'mute_duration' => 300
        ],
        'auto_delete_links' => true,
        'bad_words_filter' => true,
        'max_mentions' => 3,
        'kick_duration' => 600,
        'max_warnings' => 3
    ],
    'bad_words' => [
        'мат1', 'мат2', 'оскорбление1',
        'оскорбление2', 'запрещенное_слово'
    ]
];

class Logger {
    private $log_dir;

    public function __construct(string $log_dir) {
        $this->log_dir = $log_dir;
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0777, true);
        }
    }

    public function logEvent($event, $type = 'general'): void {
        $log_file = $this->log_dir . $type . '_' . date('Y-m-d') . '.log';
        $log_message = date('Y-m-d H:i:s') . " - " . json_encode($event, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($log_file, $log_message, FILE_APPEND);
    }

    public function logError(string $error_message): void {
        $log_file = $this->log_dir . 'errors_' . date('Y-m-d') . '.log';
        $log_message = date('Y-m-d H:i:s') . " - ОШИБКА: {$error_message}\n";
        file_put_contents($log_file, $log_message, FILE_APPEND);
    }
}

class ModerationManager {
    private $config;
    private $logger;
    private $flood_tracking = [];

    public function __construct(array $config, Logger $logger) {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function checkFloodControl($user_id, $peer_id): bool {
        $now = time();
        if (!isset($this->flood_tracking[$user_id])) {
            $this->flood_tracking[$user_id] = [];
        }

        $this->flood_tracking[$user_id] = array_filter(
            $this->flood_tracking[$user_id],
            fn($time) => $now - $time < $this->config['moderation']['flood_control']['time_window']
        );

        $this->flood_tracking[$user_id][] = $now;

        if (count($this->flood_tracking[$user_id]) > $this->config['moderation']['flood_control']['max_messages']) {
            $this->logger->logEvent(['flood_detected' => $user_id, 'peer_id' => $peer_id], 'moderation');
            return false;
        }
        return true;
    }

    public function checkBadWords(string $message): bool {
        if (!$this->config['moderation']['bad_words_filter']) return true;

        $message_lower = mb_strtolower($message);
        foreach ($this->config['bad_words'] as $bad_word) {
            if (strpos($message_lower, mb_strtolower($bad_word)) !== false) {
                return false;
            }
        }
        return true;
    }

    public function checkLinks(string $message): bool {
        if (!$this->config['moderation']['auto_delete_links']) return true;
        
        $link_pattern = '/https?:\/\/\S+/';
        return !preg_match($link_pattern, $message);
    }

    public function checkMentions(array $message): bool {
        $mentions = [];
        if (isset($message['fwd_messages'])) {
            $mentions = array_merge($mentions, array_column($message['fwd_messages'], 'from_id'));
        }
        if (isset($message['reply_message'])) {
            $mentions[] = $message['reply_message']['from_id'];
        }
        return count($mentions) <= $this->config['moderation']['max_mentions'];
    }
}

class VKChatBot {
    private $config;
    private $logger;
    private $moderationManager;
    private $longPollServer;
    private $longPollKey;
    private $longPollTs;

    public function __construct(array $config) {
        $this->config = $config;
        $this->logger = new Logger($config['log_dir']);
        $this->moderationManager = new ModerationManager($config, $this->logger);
        $this->initLongPoll();
    }

    private function initLongPoll(): void {
        $url = 'https://api.vk.com/method/groups.getLongPollServer';
        $params = [
            'access_token' => $this->config['group_token'],
            'group_id' => $this->config['group_id'],
            'v' => '5.131'
        ];
        $response = $this->sendRequest($url, $params, true);
        
        if (is_string($response)) {
            $response = json_decode($response, true);
        }
        
        if (isset($response['response'])) {
            $this->longPollServer = $response['response']['server'];
            $this->longPollKey = $response['response']['key'];
            $this->longPollTs = $response['response']['ts'];
            $this->logger->logEvent(['longpoll_init' => $response['response']], 'longpoll');
        } else {
            $this->logger->logError("Не удалось получить Long Poll сервер: " . json_encode($response));
            sleep(5);
            $this->initLongPoll(); 
        }
    }

    private function checkExpiredMutes(): void {
        if (!file_exists($this->config['mute_file'])) return;
        
        $mute_list = json_decode(file_get_contents($this->config['mute_file']), true);
        $current_time = time();
        $updated = false;
        $expired_mutes = [];
        
        foreach ($mute_list as $user_id => $expire_time) {
            if ($expire_time <= $current_time) {
                $expired_mutes[] = $user_id;
                unset($mute_list[$user_id]);
                $updated = true;
                $this->logger->logEvent([
                    'action' => 'mute_expired',
                    'user_id' => $user_id,
                    'timestamp' => $current_time
                ], 'moderation');
            }
        }
        
        if ($updated) {
            file_put_contents($this->config['mute_file'], json_encode($mute_list));
            
            if (!empty($expired_mutes)) {
                $unified_chats = file_exists($this->config['unified_chats_file']) ? 
                    json_decode(file_get_contents($this->config['unified_chats_file']), true) : [];
                
                if (!empty($unified_chats)) {
                    foreach ($expired_mutes as $user_id) {
                        $user_mention = $this->getUserMention($user_id);
                        foreach ($unified_chats as $chat_id) {
                            $this->sendMessage($chat_id, "🔊 У пользователя {$user_mention} закончился мут.");
                        }
                    }
                }
            }
        }
    }

        public function run(): void {
            $last_check_time = 0;
            
            while (true) {
                try {
                    $current_time = time();
                    if ($current_time - $last_check_time >= 10) {
                        $this->checkExpiredMutes();
                        $last_check_time = $current_time;
                    }
                    
                    $params = [
                        'act' => 'a_check',
                        'key' => $this->longPollKey,
                        'ts' => $this->longPollTs,
                        'wait' => 10 
                    ];
                    
                
                $response = $this->sendRequest($this->longPollServer, $params);
                
                if (is_string($response)) {
                    $data = json_decode($response, true);
                    
                    if (isset($data['failed'])) {
                        $this->logger->logError("Long Poll ошибка: " . json_encode($data));
                        $this->initLongPoll();
                        continue;
                    }
                    
                    if (isset($data['ts'])) {
                        $this->longPollTs = $data['ts'];
                    }
                    
                    if (isset($data['updates'])) {
                        foreach ($data['updates'] as $update) {
                            if ($update['type'] === 'message_new') {
                                $this->processMessage($update['object']['message']);
                            }
                        }
                    }
                } else {
                    $this->logger->logError("Ошибка Long Poll запроса: неожиданный тип ответа");
                    sleep(5);
                }
            } catch (Exception $e) {
                $this->logger->logError("Ошибка в основном цикле: " . $e->getMessage());
                sleep(5);
            }
        }
    }

    private function sendRequest(string $url, array $params = [], bool $isPost = false): ?string {
        $ch = curl_init();
        if ($isPost) {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        } else {
            curl_setopt($ch, CURLOPT_URL, $url . (empty($params) ? '' : '?' . http_build_query($params)));
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $this->logger->logError("cURL ошибка: " . curl_error($ch));
            curl_close($ch);
            return null;
        }
        
        curl_close($ch);
        
        return is_string($response) ? $response : null;
    }
    
    private function sendMessage(int $peer_id, string $message): void {
        $url = 'https://api.vk.com/method/messages.send';
        $params = [
            'access_token' => $this->config['group_token'],
            'v' => '5.131',
            'peer_id' => $peer_id,
            'message' => $message,
            'random_id' => random_int(1000, 999999)
        ];
        $this->logger->logEvent(['send_message' => $params], 'messages');
        $this->sendRequest($url, $params, true);
    }

    private function deleteMessage(int $message_id, int $peer_id): bool {
        $url = 'https://api.vk.com/method/messages.delete';
        $params = [
            'access_token' => $this->config['group_token'],
            'v' => '5.131',
            'conversation_message_ids' => $message_id,
            'peer_id' => $peer_id,
            'delete_for_all' => 1
        ];
        $this->logger->logEvent(['delete_message_start' => "Попытка удалить сообщение $message_id в peer_id $peer_id"], 'moderation');
        $response = $this->sendRequest($url, $params, true);

        if (is_string($response)) {
            $data = json_decode($response, true);
            if (isset($data['response']) && $data['response'] == 1) {
                $this->logger->logEvent(['delete_message_success' => "Сообщение $message_id успешно удалено"], 'moderation');
                return true;
            } elseif (isset($data['error'])) {
                $this->logger->logError("Ошибка удаления сообщения $message_id: " . $data['error']['error_msg']);
                return false;
            }
        }
        return false;
    }

    private function getChatInfo(int $peer_id): array {
        $url = 'https://api.vk.com/method/messages.getConversationsById';
        $params = [
            'access_token' => $this->config['group_token'],
            'v' => '5.131',
            'peer_ids' => $peer_id
        ];
        $response = $this->sendRequest($url, $params, true);
        
        if (is_string($response)) {
            $data = json_decode($response, true);
            if (isset($data['response']['items'][0])) {
                return $data['response']['items'][0]['chat_settings'] ?? [];
            }
        }
        return [];
    }

    private function formatTimeString(int $seconds): string {
        if ($seconds < 60) {
            return "{$seconds} сек.";
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            return "{$minutes} мин.";
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return "{$hours} ч. {$minutes} мин.";
        }
    }

private function resolveUserId(string $mention): ?string {
    if (preg_match('/\[id(\d+)\|.*?\]/', $mention, $matches)) {
        return $matches[1];
    }
    
    if (is_numeric($mention)) {
        return $mention;
    }
    
    $nicknames = file_exists($this->config['nicknames_file']) ? 
        json_decode(file_get_contents($this->config['nicknames_file']), true) : [];
    
    foreach ($nicknames as $peer_id => $users) {
        foreach ($users as $user_id => $nickname) {
            if (mb_strtolower($nickname) === mb_strtolower($mention)) {
                return $user_id;
            }
        }
    }
    
    return null;
}

    private function getUserInfo(array $user_ids): array {
        $url = 'https://api.vk.com/method/users.get';
        $params = [
            'access_token' => $this->config['group_token'],
            'v' => '5.131',
            'user_ids' => implode(',', $user_ids),
            'fields' => 'first_name,last_name'
        ];
        
        $response = $this->sendRequest($url, $params, true);
        
        if (is_string($response)) {
            $data = json_decode($response, true);
            if (isset($data['response'])) {
                return $data['response'];
            }
            $this->logger->logError("Ошибка запроса к users.get: " . json_encode($data));
        } else {
            $this->logger->logError("Ошибка запроса к users.get: неожиданный тип ответа");
        }
        
        return [];
    }
      

    private function getUserMention(string $user_id): string {
        $user_info = $this->getUserInfo([$user_id]);
        if (!empty($user_info)) {
            $user = $user_info[0];
            $first_name = $user['first_name'] ?? 'Неизвестно';
            $last_name = $user['last_name'] ?? '';
            return "[id{$user_id}|{$first_name} {$last_name}]";
        }
        return "[id{$user_id}|id{$user_id}]";
    }

    
     private function isAdmin(int $user_id): bool {
        return in_array($user_id, $this->config['admin_ids']);
    }
    
    private function isModerator(int $user_id): bool {
        if ($this->isAdmin($user_id)) {
            return true;
        }
        
        return in_array($user_id, $this->config['moderator_ids'] ?? []);
    }
    

    private function isMuted(string $user_id): bool {
        $mute_list = file_exists($this->config['mute_file']) ? json_decode(file_get_contents($this->config['mute_file']), true) : [];
        return isset($mute_list[$user_id]) && $mute_list[$user_id] > time();
    }

    private function isKicked(string $user_id): bool {
        $kick_list = file_exists($this->config['kick_file']) ? json_decode(file_get_contents($this->config['kick_file']), true) : [];
        return isset($kick_list[$user_id]) && $kick_list[$user_id] > time();
    }

    private function isBanned(string $user_id): bool {
        $ban_list = file_exists($this->config['ban_file']) ? json_decode(file_get_contents($this->config['ban_file']), true) : [];
        return in_array($user_id, $ban_list);
    }

    private function removeUserFromChat(int $peer_id, string $user_id): void {
        $url = 'https://api.vk.com/method/messages.removeChatUser';
        $params = [
            'access_token' => $this->config['group_token'],
            'v' => '5.131',
            'chat_id' => $peer_id - 2000000000,
            'user_id' => $user_id
        ];
        $this->sendRequest($url, $params, true);
    }

    public function muteUser(int $peer_id, string $user_id, int $duration = 300, bool $is_sync = false): void {
        $mute_list = file_exists($this->config['mute_file']) ? json_decode(file_get_contents($this->config['mute_file']), true) : [];
        $mute_list[$user_id] = time() + $duration;
        file_put_contents($this->config['mute_file'], json_encode($mute_list));
        
        $user_mention = $this->getUserMention($user_id);
        $time_str = $this->formatTimeString($duration);
        
        if (!$is_sync) {
            $this->sendMessage($peer_id, "🔇 Пользователь {$user_mention} заглушен на {$time_str}");
            
            if ($this->config['unified_mode']) {
                $this->syncActionAcrossChats($user_id, 'mute', $peer_id, ['duration' => $duration]);
            }
        }
        
        $this->logger->logEvent([
            'action' => 'mute_user',
            'user_id' => $user_id,
            'peer_id' => $peer_id,
            'duration' => $duration,
            'is_sync' => $is_sync,
            'timestamp' => time()
        ], 'moderation');
    }

    public function unmuteUser(int $peer_id, string $user_id, bool $is_sync = false): void {
        $mute_list = file_exists($this->config['mute_file']) ? json_decode(file_get_contents($this->config['mute_file']), true) : [];
        
        if (isset($mute_list[$user_id])) {
            unset($mute_list[$user_id]);
            file_put_contents($this->config['mute_file'], json_encode($mute_list));
            
            $user_mention = $this->getUserMention($user_id);
            
            if (!$is_sync) {
                $this->sendMessage($peer_id, "🔊 С пользователя {$user_mention} снята заглушка");
                
                if ($this->config['unified_mode']) {
                    $this->syncActionAcrossChats($user_id, 'unmute', $peer_id);
                }
            }
            
            $this->logger->logEvent([
                'action' => 'unmute_user',
                'user_id' => $user_id,
                'peer_id' => $peer_id,
                'is_sync' => $is_sync,
                'timestamp' => time()
            ], 'moderation');
        } else if (!$is_sync) {
            $user_mention = $this->getUserMention($user_id);
            $this->sendMessage($peer_id, "❌ Пользователь {$user_mention} не находится в списке заглушенных");
        }
    }

    public function banUser(int $peer_id, string $user_id, bool $is_sync = false): void {
        $ban_list = file_exists($this->config['ban_file']) ? json_decode(file_get_contents($this->config['ban_file']), true) : [];
        
        if (!in_array($user_id, $ban_list)) {
            $ban_list[] = $user_id;
            file_put_contents($this->config['ban_file'], json_encode($ban_list));
            
            $this->removeUserFromChat($peer_id, $user_id);
            
            $user_mention = $this->getUserMention($user_id);
            
            if (!$is_sync) {
                $this->sendMessage($peer_id, "⛔ Пользователь {$user_mention} заблокирован в беседе");
                
                if ($this->config['unified_mode']) {
                    $this->syncActionAcrossChats($user_id, 'ban', $peer_id);
                }
            }
            
            $this->logger->logEvent([
                'action' => 'ban_user',
                'user_id' => $user_id,
                'peer_id' => $peer_id,
                'is_sync' => $is_sync,
                'timestamp' => time()
            ], 'moderation');
        } else if (!$is_sync) {
            $user_mention = $this->getUserMention($user_id);
            $this->sendMessage($peer_id, "❌ Пользователь {$user_mention} уже заблокирован");
        }
    }

    public function unbanUser(int $peer_id, string $user_id, bool $is_sync = false): void {
        $ban_list = file_exists($this->config['ban_file']) ? json_decode(file_get_contents($this->config['ban_file']), true) : [];
        
        if (($key = array_search($user_id, $ban_list)) !== false) {
            unset($ban_list[$key]);
            file_put_contents($this->config['ban_file'], json_encode(array_values($ban_list)));
            
            $user_mention = $this->getUserMention($user_id);
            
            if (!$is_sync) {
                $this->sendMessage($peer_id, "✅ С пользователя {$user_mention} снята блокировка");
                
                if ($this->config['unified_mode']) {
                    $this->syncActionAcrossChats($user_id, 'unban', $peer_id);
                }
            }
            
            $this->logger->logEvent([
                'action' => 'unban_user',
                'user_id' => $user_id,
                'peer_id' => $peer_id,
                'is_sync' => $is_sync,
                'timestamp' => time()
            ], 'moderation');
        } else if (!$is_sync) {
            $user_mention = $this->getUserMention($user_id);
            $this->sendMessage($peer_id, "❌ Пользователь {$user_mention} не находится в списке заблокированных");
        }
    }

    public function kickUser(int $peer_id, string $user_id, bool $is_sync = false): void {
        $kick_list = file_exists($this->config['kick_file']) ? json_decode(file_get_contents($this->config['kick_file']), true) : [];
        $kick_list[$user_id] = time() + $this->config['moderation']['kick_duration'];
        file_put_contents($this->config['kick_file'], json_encode($kick_list));
        
        $this->removeUserFromChat($peer_id, $user_id);
        
        $user_mention = $this->getUserMention($user_id);
        $time_str = $this->formatTimeString($this->config['moderation']['kick_duration']);
        
        if (!$is_sync) {
            $this->sendMessage($peer_id, "👢 Пользователь {$user_mention} исключен из беседы на {$time_str}");
            
            if ($this->config['unified_mode']) {
                $this->syncActionAcrossChats($user_id, 'kick', $peer_id);
            }
        }
        
        $this->logger->logEvent([
            'action' => 'kick_user',
            'user_id' => $user_id,
            'peer_id' => $peer_id,
            'duration' => $this->config['moderation']['kick_duration'],
            'is_sync' => $is_sync,
            'timestamp' => time()
        ], 'moderation');
    }

    public function setNickname(int $peer_id, string $user_id, string $nickname, int $admin_id, bool $is_sync = false): void {
        if (!$is_sync && !$this->isAdmin($admin_id)) {
            $this->sendMessage($peer_id, "⛔ Только администраторы могут устанавливать никнеймы");
            return;
        }
        
        $nicknames = file_exists($this->config['nicknames_file']) ? 
            json_decode(file_get_contents($this->config['nicknames_file']), true) : [];
        
        if (!isset($nicknames[$peer_id])) {
            $nicknames[$peer_id] = [];
        }
        
        $nicknames[$peer_id][$user_id] = $nickname;
        file_put_contents($this->config['nicknames_file'], json_encode($nicknames));
        
        $user_mention = $this->getUserMention($user_id);
        
        if (!$is_sync) {
            $this->sendMessage($peer_id, "👤 Пользователю {$user_mention} установлен никнейм \"{$nickname}\"");
            
            if ($this->config['unified_mode']) {
                $this->syncActionAcrossChats($user_id, 'nickname', $peer_id, [
                    'nickname' => $nickname,
                    'admin_id' => $admin_id
                ]);
            }
        }
        
        $this->logger->logEvent([
            'action' => 'set_nickname',
            'user_id' => $user_id,
            'peer_id' => $peer_id,
            'nickname' => $nickname,
            'admin_id' => $admin_id,
            'is_sync' => $is_sync,
            'timestamp' => time()
        ], 'moderation');
    }

    public function warnUser(int $peer_id, string $user_id, string $reason = "", bool $is_sync = false): void {
        $warn_list = file_exists($this->config['warn_file']) ? 
            json_decode(file_get_contents($this->config['warn_file']), true) : [];
        
        if (!isset($warn_list[$peer_id])) {
            $warn_list[$peer_id] = [];
        }
        
        if (!isset($warn_list[$peer_id][$user_id])) {
            $warn_list[$peer_id][$user_id] = 0;
        }
        
        $warn_list[$peer_id][$user_id]++;
        $warn_count = $warn_list[$peer_id][$user_id];
        file_put_contents($this->config['warn_file'], json_encode($warn_list));
        
        $user_mention = $this->getUserMention($user_id);
        
        if (!$is_sync) {
            $reason_text = $reason ? " Причина: {$reason}" : "";
            $this->sendMessage($peer_id, "⚠️ Пользователь {$user_mention} получил предупреждение ({$warn_count}/{$this->config['moderation']['max_warnings']}).{$reason_text}");
            
            if ($warn_count >= $this->config['moderation']['max_warnings']) {
                $this->muteUser($peer_id, $user_id, $this->config['moderation']['flood_control']['mute_duration']);
                $this->sendMessage($peer_id, "🔇 Пользователь {$user_mention} заглушен за превышение количества предупреждений");
                
                $warn_list[$peer_id][$user_id] = 0;
                file_put_contents($this->config['warn_file'], json_encode($warn_list));
            }
            
            if ($this->config['unified_mode']) {
                $this->syncActionAcrossChats($user_id, 'warn', $peer_id, ['reason' => $reason]);
            }
        }
        
        $this->logger->logEvent([
            'action' => 'warn_user',
            'user_id' => $user_id,
            'peer_id' => $peer_id,
            'warn_count' => $warn_count,
            'reason' => $reason,
            'is_sync' => $is_sync,
            'timestamp' => time()
        ], 'moderation');
    }

    public function unwarnUser(int $peer_id, string $user_id, bool $is_sync = false): void {
        $warn_list = file_exists($this->config['warn_file']) ? 
            json_decode(file_get_contents($this->config['warn_file']), true) : [];
        
        if (isset($warn_list[$peer_id][$user_id]) && $warn_list[$peer_id][$user_id] > 0) {
            $warn_list[$peer_id][$user_id]--;
            $warn_count = $warn_list[$peer_id][$user_id];
            file_put_contents($this->config['warn_file'], json_encode($warn_list));
            
            $user_mention = $this->getUserMention($user_id);
            
            if (!$is_sync) {
                $this->sendMessage($peer_id, "✅ С пользователя {$user_mention} снято предупреждение (осталось: {$warn_count}/{$this->config['moderation']['max_warnings']})");
                
                if ($this->config['unified_mode']) {
                    $this->syncActionAcrossChats($user_id, 'unwarn', $peer_id);
                }
            }
            
            $this->logger->logEvent([
                'action' => 'unwarn_user',
                'user_id' => $user_id,
                'peer_id' => $peer_id,
                'warn_count' => $warn_count,
                'is_sync' => $is_sync,
                'timestamp' => time()
            ], 'moderation');
        } else if (!$is_sync) {
            $user_mention = $this->getUserMention($user_id);
            $this->sendMessage($peer_id, "❌ У пользователя {$user_mention} нет предупреждений");
        }
    }

    private function syncActionAcrossChats(string $user_id, string $action, int $initiating_peer_id = null, $data = null): void {
        if (!file_exists($this->config['unified_chats_file'])) return;
        
        $unified_chats = json_decode(file_get_contents($this->config['unified_chats_file']), true);
        if (empty($unified_chats)) return;
        
        $user_mention = $this->getUserMention($user_id);
        
        foreach ($unified_chats as $chat_id) {
            if ($chat_id == $initiating_peer_id) continue;
            
            switch ($action) {
                case 'mute':
                    $duration = $data['duration'] ?? $this->config['moderation']['flood_control']['mute_duration'];
                    $this->muteUser($chat_id, $user_id, $duration, true);
                    $time_str = $this->formatTimeString($duration);
                    $this->sendMessage($chat_id, "🔄 Синхронизация: пользователь {$user_mention} заглушен на {$time_str}");
                    break;
                case 'unmute':
                    $this->unmuteUser($chat_id, $user_id, true);
                    $this->sendMessage($chat_id, "🔄 Синхронизация: с пользователя {$user_mention} снята заглушка");
                    break;
                case 'ban':
                    $this->banUser($chat_id, $user_id, true);
                    $this->sendMessage($chat_id, "🔄 Синхронизация: пользователь {$user_mention} заблокирован");
                    break;
                case 'unban':
                    $this->unbanUser($chat_id, $user_id, true);
                    $this->sendMessage($chat_id, "🔄 Синхронизация: с пользователя {$user_mention} снята блокировка");
                    break;
                case 'kick':
                    $this->kickUser($chat_id, $user_id, true);
                    $this->sendMessage($chat_id, "🔄 Синхронизация: пользователь {$user_mention} исключен");
                    break;
                case 'nickname':
                    if (isset($data['nickname']) && isset($data['admin_id'])) {
                        $this->setNickname($chat_id, $user_id, $data['nickname'], $data['admin_id'], true);
                        $this->sendMessage($chat_id, "🔄 Синхронизация: пользователю {$user_mention} установлен никнейм \"{$data['nickname']}\"");
                    }
                    break;
                case 'warn':
                    if (isset($data['reason'])) {
                        $this->warnUser($chat_id, $user_id, $data['reason'], true);
                        $this->sendMessage($chat_id, "🔄 Синхронизация: пользователь {$user_mention} получил предупреждение");
                    }
                    break;
                case 'unwarn':
                    $this->unwarnUser($chat_id, $user_id, true);
                    $this->sendMessage($chat_id, "🔄 Синхронизация: с пользователя {$user_mention} снято предупреждение");
                    break;
            }
        }
    }

    private function enableUnifiedMode(int $peer_id, int $admin_id): void {
        if (!$this->isAdmin($admin_id)) {
            $this->sendMessage($peer_id, "⛔ Только администраторы могут включать объединение чатов.");
            return;
        }
    
        $unified_chats = file_exists($this->config['unified_chats_file']) ? 
            json_decode(file_get_contents($this->config['unified_chats_file']), true) : [];
    
        if (!in_array($peer_id, $unified_chats)) {
            $unified_chats[] = $peer_id;
            file_put_contents($this->config['unified_chats_file'], json_encode($unified_chats));
            
            $chat_info = $this->getChatInfo($peer_id);
            $chat_title = $chat_info['title'] ?? 'Беседа #' . $peer_id;
            foreach ($unified_chats as $chat_id) {
                if ($chat_id != $peer_id) {
                    $this->sendMessage($chat_id, "🔄 К сети добавлена беседа: {$chat_title}");
                }
            }
            $this->sendMessage($peer_id, "✅ Этот чат теперь объединён с другими.");
        } else {
            $this->sendMessage($peer_id, "ℹ️ Этот чат уже объединён.");
        }
    }

    private function disableUnifiedMode(int $peer_id, int $admin_id): void {
        if (!$this->isAdmin($admin_id)) {
            $this->sendMessage($peer_id, "⛔ Только администраторы могут отключать объединение чатов.");
            return;
        }
    
        $unified_chats = file_exists($this->config['unified_chats_file']) ? 
            json_decode(file_get_contents($this->config['unified_chats_file']), true) : [];
    
        if (($key = array_search($peer_id, $unified_chats)) !== false) {
            unset($unified_chats[$key]);
            file_put_contents($this->config['unified_chats_file'], json_encode(array_values($unified_chats)));
            
            $chat_info = $this->getChatInfo($peer_id);
            $chat_title = $chat_info['title'] ?? 'Беседа #' . $peer_id;
            foreach ($unified_chats as $chat_id) {
                $this->sendMessage($chat_id, "🔄 Беседа отключена от сети: {$chat_title}");
            }
            $this->sendMessage($peer_id, "❌ Этот чат больше не объединён.");
        } else {
            $this->sendMessage($peer_id, "ℹ️ Этот чат не был объединён.");
        }
    }

    private function getUserStats(int $peer_id, string $user_id): array {
        $stats_file = $this->config['data_dir'] . '/user_stats.json';
        $stats = file_exists($stats_file) ? json_decode(file_get_contents($stats_file), true) : [];
        
        if (!isset($stats[$peer_id])) {
            $stats[$peer_id] = [];
        }
        
        if (!isset($stats[$peer_id][$user_id])) {
            $stats[$peer_id][$user_id] = [
                'join_date' => null,
                'message_count' => 0,
                'last_message' => null
            ];
        }
        
        return $stats[$peer_id][$user_id];
    }
    
    private function saveToFile($file_path, $data): bool {
        try {
            $dir = dirname($file_path);
            if (!file_exists($dir)) {
                mkdir($dir, 0777, true);
            }
            
            $result = file_put_contents($file_path, json_encode($data));
            return $result !== false;
        } catch (Exception $e) {
            $this->logger->logError("Ошибка при записи в файл {$file_path}: " . $e->getMessage());
            return false;
        }
    }
    
    private function updateUserStats(int $peer_id, string $user_id, string $action = 'message'): void {
            $stats_file = $this->config['data_dir'] . '/user_stats.json';
            $stats = file_exists($stats_file) ? json_decode(file_get_contents($stats_file), true) : [];
            
            if (!isset($stats[$peer_id])) {
                $stats[$peer_id] = [];
            }
            
            if (!isset($stats[$peer_id][$user_id])) {
                $stats[$peer_id][$user_id] = [
                    'join_date' => null,
                    'message_count' => 0,
                    'last_message' => null
                ];
            }
            
            if ($action === 'join' && $stats[$peer_id][$user_id]['join_date'] === null) {
                $stats[$peer_id][$user_id]['join_date'] = time();
            } elseif ($action === 'message') {
                $stats[$peer_id][$user_id]['message_count']++;
                $stats[$peer_id][$user_id]['last_message'] = time();
                
                if ($stats[$peer_id][$user_id]['join_date'] === null) {
                    $stats[$peer_id][$user_id]['join_date'] = time();
                }
            }
            
            file_put_contents($stats_file, json_encode($stats));
        }

        public function processMessage(array $message): void {
            $peer_id = $message['peer_id'];
            $from_id = $message['from_id'];
            $text = $message['text'] ?? '';
    
            if (isset($message['action'])) {
                if ($message['action']['type'] == 'chat_invite_user' || $message['action']['type'] == 'chat_invite_user_by_link') {
                    $invited_user_id = $message['action']['member_id'] ?? $from_id;
                    
                    if ($invited_user_id == -$this->config['group_id']) {
                        $this->sendMessage($peer_id, "👋 Привет всем! Я бот-модератор для конференции проекта Arizona Nevermore. Я помогу вам управлять беседой и следить за порядком.\n\n".
                            "📝 Основные команды для пользователей чата:\n".
                            "• /help - список всех команд\n".
                            "• /stats - просмотреть свою статистику\n".
                            "• /admins - просмотреть список Администраторов беседы\n".
                            "• /moders - просмотреть список Модераторов беседы\n\n".
                            "Рад быть полезным! 🤖");
                    } else {
                        $user_mention = $this->getUserMention($invited_user_id);
                        $this->sendMessage($peer_id, "👋 Добро пожаловать, {$user_mention}! Используйте /help для просмотра доступных команд.");
                        $this->updateUserStats($peer_id, $invited_user_id, 'join');
                    }
                }
            }
            
            $this->updateUserStats($peer_id, $from_id, 'message');
            
        $parts = explode(' ', $text);
        $command = strtolower($parts[0] ?? '');
                
        $reply_user_id = null;
        if (isset($message['reply_message']) && isset($message['reply_message']['from_id'])) {
            $reply_user_id = $message['reply_message']['from_id'];
        }
        
        switch ($command) {
            case '!mute':
                case '/mute':
                    if (!$this->isModerator($from_id)) {
                        $this->sendMessage($peer_id, "⛔ Только администраторы и модераторы могут использовать эту команду");
                        break;
                    }
                    
                    $this->logger->logEvent([
                        'debug_mute_command' => [
                            'from_id' => $from_id,
                            'reply_message' => isset($message['reply_message']) ? 'есть' : 'нет',
                            'reply_user_id' => $reply_user_id,
                            'parts' => $parts,
                            'raw_message' => $message
                        ]
                    ], 'debug');
                    
                    $user_id = null;
                    $duration_index = 2;
                    
                    if (isset($message['reply_message']) && isset($message['reply_message']['from_id'])) {
                        $user_id = (string)$message['reply_message']['from_id'];
                        $duration_index = 1; // Сдвигаем индекс для времени
                        $this->logger->logEvent(['debug_mute_reply' => "Используем ID из ответа: $user_id"], 'debug');
                    }
                    elseif (count($parts) >= 2 && $this->resolveUserId($parts[1])) {
                        $user_id = $this->resolveUserId($parts[1]);
                        $this->logger->logEvent(['debug_mute_mention' => "Используем ID из упоминания: $user_id"], 'debug');
                    } 
                    else {
                        $this->sendMessage($peer_id, "❌ Использование: !mute @упоминание [время в секундах] или ответьте на сообщение пользователя");
                        break;
                    }
                    
                    if (empty($user_id)) {
                        $this->sendMessage($peer_id, "❌ Не удалось определить ID пользователя");
                        $this->logger->logEvent(['debug_mute_error' => "Пустой user_id"], 'debug');
                        break;
                    }
                    
                    if ($this->isAdmin(intval($user_id))) {
                        $this->sendMessage($peer_id, "⛔ Нельзя заглушить администратора/модератора");
                        break;
                    }
                    
                    $duration = isset($parts[$duration_index]) && is_numeric($parts[$duration_index]) ? 
                        intval($parts[$duration_index]) : $this->config['moderation']['flood_control']['mute_duration'];
                    
                    $this->logger->logEvent([
                        'debug_mute_final' => [
                            'user_id' => $user_id,
                            'duration' => $duration,
                            'duration_index' => $duration_index
                        ]
                    ], 'debug');
                    
                    $this->muteUser($peer_id, $user_id, $duration);
                    break;
                
            case '!unmute':
            case '/unmute':
                if (!$this->isModerator($from_id)) {
                    $this->sendMessage($peer_id, "⛔ Только администраторы и модераторы могут использовать эту команду");
                    break;
                }
                
                $user_id = null;
                if (count($parts) >= 2 && $this->resolveUserId($parts[1])) {
                    $user_id = $this->resolveUserId($parts[1]);
                } elseif ($reply_user_id) {
                    $user_id = $reply_user_id;
                } else {
                    $this->sendMessage($peer_id, "❌ Использование: !unmute @упоминание или ответьте на сообщение пользователя");
                    break;
                }
                
                $this->unmuteUser($peer_id, $user_id);
                break;
                
            case '!ban':
            case '/ban':
                if (!$this->isModerator($from_id)) {
                    $this->sendMessage($peer_id, "⛔ Только администраторы и модераторы могут использовать эту команду");
                    break;
                }
                
                $user_id = null;
                if (count($parts) >= 2 && $this->resolveUserId($parts[1])) {
                    $user_id = $this->resolveUserId($parts[1]);
                } elseif ($reply_user_id) {
                    $user_id = $reply_user_id;
                } else {
                    $this->sendMessage($peer_id, "❌ Использование: !ban @упоминание или ответьте на сообщение пользователя");
                    break;
                }
                
                $this->banUser($peer_id, $user_id);
                break;
                
            case '!unban':
            case '/unban':
                if (!$this->isModerator($from_id)) {
                    $this->sendMessage($peer_id, "⛔ Только администраторы и модераторы могут использовать эту команду");
                    break;
                }
                
                $user_id = null;
                if (count($parts) >= 2 && $this->resolveUserId($parts[1])) {
                    $user_id = $this->resolveUserId($parts[1]);
                } elseif ($reply_user_id) {
                    $user_id = $reply_user_id;
                } else {
                    $this->sendMessage($peer_id, "❌ Использование: !unban @упоминание или ответьте на сообщение пользователя");
                    break;
                }
                
                $this->unbanUser($peer_id, $user_id);
                break;
                
            case '!kick':
            case '/kick':
                if (!$this->isModerator($from_id)) {
                    $this->sendMessage($peer_id, "⛔ Только администраторы и модераторы могут использовать эту команду");
                    break;
                }
                
                $user_id = null;
                if (count($parts) >= 2 && $this->resolveUserId($parts[1])) {
                    $user_id = $this->resolveUserId($parts[1]);
                } elseif ($reply_user_id) {
                    $user_id = $reply_user_id;
                } else {
                    $this->sendMessage($peer_id, "❌ Использование: !kick @упоминание или ответьте на сообщение пользователя");
                    break;
                }
                
                $this->kickUser($peer_id, $user_id);
                break;
                
            case '!warn':
            case '/warn':
                if (!$this->isModerator($from_id)) {
                    $this->sendMessage($peer_id, "⛔ Только администраторы и модераторы могут использовать эту команду");
                    break;
                }
                
                $user_id = null;
                $reason_index = 2;
                
                if (count($parts) >= 2 && $this->resolveUserId($parts[1])) {
                    $user_id = $this->resolveUserId($parts[1]);
                } elseif ($reply_user_id) {
                    $user_id = $reply_user_id;
                    $reason_index = 1; 
                } else {
                    $this->sendMessage($peer_id, "❌ Использование: !warn @упоминание [причина] или ответьте на сообщение пользователя");
                    break;
                }
                
                $reason = count($parts) > $reason_index ? implode(' ', array_slice($parts, $reason_index)) : "";
                $this->warnUser($peer_id, $user_id, $reason);
                break;
                
            case '!unwarn':
            case '/unwarn':
                if (!$this->isModerator($from_id)) {
                    $this->sendMessage($peer_id, "⛔ Только администраторы и модераторы могут использовать эту команду");
                    break;
                }
                
                $user_id = null;
                if (count($parts) >= 2 && $this->resolveUserId($parts[1])) {
                    $user_id = $this->resolveUserId($parts[1]);
                } elseif ($reply_user_id) {
                    $user_id = $reply_user_id;
                } else {
                    $this->sendMessage($peer_id, "❌ Использование: !unwarn @упоминание или ответьте на сообщение пользователя");
                    break;
                }
                
                $this->unwarnUser($peer_id, $user_id);
                break;
                
            case '!nick':
            case '/nick':
                if (!$this->isModerator($from_id)) {
                    $this->sendMessage($peer_id, "⛔ Только администраторы и модераторы могут использовать эту команду");
                    break;
                }
                
                $user_id = null;
                $nickname_index = 2;
                
                if (count($parts) >= 3 && $this->resolveUserId($parts[1])) {
                    $user_id = $this->resolveUserId($parts[1]);
                } elseif ($reply_user_id && count($parts) >= 2) {
                    $user_id = $reply_user_id;
                    $nickname_index = 1; 
                } else {
                    $this->sendMessage($peer_id, "❌ Использование: !nick @упоминание [новый_никнейм] или ответьте на сообщение пользователя");
                    break;
                }
                
                $nickname = implode(' ', array_slice($parts, $nickname_index));
                $this->setNickname($peer_id, $user_id, $nickname, $from_id);
                break;
                
                case '!stats':
                    case '/stats':
                        $user_id = $from_id;
                        
                        $viewing_other_user = false;
                        if (count($parts) > 1 && $this->resolveUserId($parts[1])) {
                            $target_user_id = $this->resolveUserId($parts[1]);
                            $viewing_other_user = ($target_user_id != $from_id);
                            $user_id = $target_user_id;
                        } elseif ($reply_user_id) {
                            $viewing_other_user = ($reply_user_id != $from_id);
                            $user_id = $reply_user_id;
                        }
                        
                        if ($viewing_other_user && !$this->isModerator($from_id)) {
                            $this->sendMessage($peer_id, "⛔ Только администраторы и модераторы могут просматривать статистику других пользователей");
                            break;
                        }
                        
                        $user_mention = $this->getUserMention($user_id);
                        
                        $nickname = "Не установлен";
                        $nicknames = file_exists($this->config['nicknames_file']) ? 
                            json_decode(file_get_contents($this->config['nicknames_file']), true) : [];
                        if (isset($nicknames[$peer_id][$user_id])) {
                            $nickname = $nicknames[$peer_id][$user_id];
                        }
                        
                        $warn_count = 0;
                        $warn_list = file_exists($this->config['warn_file']) ? 
                            json_decode(file_get_contents($this->config['warn_file']), true) : [];
                        if (isset($warn_list[$peer_id][$user_id])) {
                            $warn_count = $warn_list[$peer_id][$user_id];
                        }
                                                
                        $mute_status = "Нет";
                        $mute_list = file_exists($this->config['mute_file']) ? 
                            json_decode(file_get_contents($this->config['mute_file']), true) : [];
                        if (isset($mute_list[$user_id]) && $mute_list[$user_id] > time()) {
                            $remaining = $mute_list[$user_id] - time();
                            $mute_status = "Да (осталось " . $this->formatTimeString($remaining) . ")";
                        }
                        
                        $ban_status = "Нет";
                        $ban_list = file_exists($this->config['ban_file']) ? 
                            json_decode(file_get_contents($this->config['ban_file']), true) : [];
                        if (in_array($user_id, $ban_list)) {
                            $ban_status = "Да";
                        }
                        
                        $user_stats = $this->getUserStats($peer_id, $user_id);
                        $join_date = $user_stats['join_date'] ? date('d.m.Y H:i', $user_stats['join_date']) : 'Неизвестно';
                        $message_count = $user_stats['message_count'] ?? 0;
                        $last_message_date = $user_stats['last_message'] ? date('d.m.Y H:i', $user_stats['last_message']) : 'Неизвестно';
                        
                        $stats_message = "📊 Статистика пользователя {$user_mention}:\n\n" .
                                        "👤 Никнейм: {$nickname}\n" .
                                        "📅 В беседе с: {$join_date}\n" .
                                        "💬 Сообщений: {$message_count}\n" .
                                        "🕒 Последнее сообщение: {$last_message_date}\n" .
                                        "⚠️ Предупреждения: {$warn_count}/{$this->config['moderation']['max_warnings']}\n" .
                                        "🔇 Мут: {$mute_status}\n" .
                                        "⛔ Бан: {$ban_status}\n";
                        
                        $this->sendMessage($peer_id, $stats_message);
                        break;
                        
                    case '!unite':
                    case '/unite':
                        if (!$this->isAdmin($from_id)) {
                            $this->sendMessage($peer_id, "⛔ Только администраторы могут использовать эту команду");
                            break;
                        }
                        $this->enableUnifiedMode($peer_id, $from_id);
                        break;
                
            case '!separate':
            case '/separate':
                if (!$this->isAdmin($from_id)) {
                    $this->sendMessage($peer_id, "⛔ Только администраторы могут использовать эту команду");
                    break;
                }
                $this->disableUnifiedMode($peer_id, $from_id);
                break;
                
                case '!unified':
                    case '/unified':
                        if (!$this->isAdmin($from_id)) {
                            $this->sendMessage($peer_id, "⛔ Только администраторы могут просматривать список объединённых бесед");
                            break;
                        }
                        $unified_chats = file_exists($this->config['unified_chats_file']) ? 
                            json_decode(file_get_contents($this->config['unified_chats_file']), true) : [];
                        $message = "📋 Объединённые беседы:\n";
                        foreach ($unified_chats as $chat_id) {
                            $message .= "- ID: {$chat_id}\n";
                        }
                        $this->sendMessage($peer_id, $message);
                        break;
                        
                    case '!addadmin':
                    case '/addadmin':
                        if (!in_array($from_id, $this->config['super_admin_ids'] ?? [$this->config['admin_ids'][0]])) {
                            $this->sendMessage($peer_id, "⛔ Только главные администраторы могут добавлять новых администраторов");
                            break;
                        }
                        
                        $user_id = null;
                        if (count($parts) >= 2 && $this->resolveUserId($parts[1])) {
                            $user_id = $this->resolveUserId($parts[1]);
                        } elseif ($reply_user_id) {
                            $user_id = $reply_user_id;
                        } else {
                            $this->sendMessage($peer_id, "❌ Использование: !addadmin @упоминание или ответьте на сообщение пользователя");
                            break;
                        }
                        
                        if (in_array(intval($user_id), $this->config['admin_ids'])) {
                            $this->sendMessage($peer_id, "ℹ️ Этот пользователь уже является администратором");
                            break;
                        }
                        
                        $this->config['admin_ids'][] = intval($user_id);
                        $config_file = __DIR__ . '/config.php';
                        $config_content = file_get_contents($config_file);
                        
                        $admin_ids_str = var_export($this->config['admin_ids'], true);
                        $config_content = preg_replace(
                            "/('admin_ids'\s*=>\s*)array\s*\([^)]*\)/",
                            "$1$admin_ids_str",
                            $config_content
                        );
                        
                        file_put_contents($config_file, $config_content);
                        
                        $user_mention = $this->getUserMention($user_id);
                        $this->sendMessage($peer_id, "✅ Пользователь {$user_mention} добавлен в список администраторов");
                        break;
                        
                    case '!removeadmin':
                    case '/removeadmin':
                        if (!in_array($from_id, $this->config['super_admin_ids'] ?? [$this->config['admin_ids'][0]])) {
                            $this->sendMessage($peer_id, "⛔ Только главные администраторы могут удалять администраторов");
                            break;
                        }
                        
                        $user_id = null;
                        if (count($parts) >= 2 && $this->resolveUserId($parts[1])) {
                            $user_id = $this->resolveUserId($parts[1]);
                        } elseif ($reply_user_id) {
                            $user_id = $reply_user_id;
                        } else {
                            $this->sendMessage($peer_id, "❌ Использование: !removeadmin @упоминание или ответьте на сообщение пользователя");
                            break;
                        }
                        
                        if (!in_array(intval($user_id), $this->config['admin_ids'])) {
                            $this->sendMessage($peer_id, "ℹ️ Этот пользователь не является администратором");
                            break;
                        }
                        
                        if (in_array(intval($user_id), $this->config['super_admin_ids'] ?? [$this->config['admin_ids'][0]])) {
                            $this->sendMessage($peer_id, "⛔ Нельзя удалить главного администратора");
                            break;
                        }
                        
                        $admin_ids = $this->config['admin_ids'];
                        $key = array_search(intval($user_id), $admin_ids);
                        if ($key !== false) {
                            unset($admin_ids[$key]);
                            $this->config['admin_ids'] = array_values($admin_ids);
                            
                            $config_file = __DIR__ . '/config.php';
                            $config_content = file_get_contents($config_file);
                            
                            $admin_ids_str = var_export($this->config['admin_ids'], true);
                            $config_content = preg_replace(
                                "/('admin_ids'\s*=>\s*)array\s*\([^)]*\)/",
                                "$1$admin_ids_str",
                                $config_content
                            );
                            
                            file_put_contents($config_file, $config_content);
                            
                            $user_mention = $this->getUserMention($user_id);
                            $this->sendMessage($peer_id, "✅ Пользователь {$user_mention} удален из списка администраторов");
                        }
                        break;
                        
                        case '!admins':
                            case '/admins':
                                $admin_ids = $this->config['admin_ids'];
                                $message = "👑 Список администраторов:\n\n";
                                
                                $nicknames = file_exists($this->config['nicknames_file']) ? 
                                    json_decode(file_get_contents($this->config['nicknames_file']), true) : [];
                                
                                foreach ($admin_ids as $admin_id) {
                                    $admin_mention = $this->getUserMention($admin_id);
                                    
                                    $nickname = "";
                                    if (isset($nicknames[$peer_id][$admin_id])) {
                                        $nickname = " (" . $nicknames[$peer_id][$admin_id] . ")";
                                    }
                                    
                                    if (in_array($admin_id, $this->config['super_admin_ids'] ?? [$this->config['admin_ids'][0]])) {
                                        $message .= "👑 {$admin_mention}{$nickname} - Главный администратор\n";
                                    } else {
                                        $message .= "⭐ {$admin_mention}{$nickname}\n";
                                    }
                                }
                                
                                $this->sendMessage($peer_id, $message);
                                break;

    
    case '!addmoder':
        case '/addmoder':
            if (!$this->isAdmin($from_id)) {
                $this->sendMessage($peer_id, "⛔ Только администраторы могут добавлять модераторов");
                break;
            }
            
            $user_id = null;
            if (count($parts) >= 2 && $this->resolveUserId($parts[1])) {
                $user_id = $this->resolveUserId($parts[1]);
            } elseif ($reply_user_id) {
                $user_id = $reply_user_id;
            } else {
                $this->sendMessage($peer_id, "❌ Использование: /addmoder @упоминание или ответьте на сообщение пользователя");
                break;
            }
            
            if ($this->isAdmin(intval($user_id))) {
                $this->sendMessage($peer_id, "ℹ️ Этот пользователь уже является администратором");
                break;
            }
            
            if (in_array(intval($user_id), $this->config['moderator_ids'] ?? [])) {
                $this->sendMessage($peer_id, "ℹ️ Этот пользователь уже является модератором");
                break;
            }
            
            if (!isset($this->config['moderator_ids'])) {
                $this->config['moderator_ids'] = [];
            }
            
            $this->config['moderator_ids'][] = intval($user_id);
            $config_file = __DIR__ . '/config.php';
            $config_content = file_get_contents($config_file);
            
            if (strpos($config_content, "'moderator_ids'") !== false) {
                $moderator_ids_str = var_export($this->config['moderator_ids'], true);
                $config_content = preg_replace(
                    "/('moderator_ids'\s*=>\s*)array\s*\([^)]*\)/",
                    "$1$moderator_ids_str",
                    $config_content
                );
            } else {
                $moderator_ids_str = var_export($this->config['moderator_ids'], true);
                $config_content = preg_replace(
                    "/(return\s*\[\s*)/",
                    "$1'moderator_ids' => $moderator_ids_str,\n    ",
                    $config_content
                );
            }
            
            file_put_contents($config_file, $config_content);
            
            $user_mention = $this->getUserMention($user_id);
            $this->sendMessage($peer_id, "✅ Пользователь {$user_mention} добавлен в список модераторов");
            break;
            
        case '!removemoder':
        case '/removemoder':
            if (!$this->isAdmin($from_id)) {
                $this->sendMessage($peer_id, "⛔ Только администраторы могут удалять модераторов");
                break;
            }
            
            $user_id = null;
            if (count($parts) >= 2 && $this->resolveUserId($parts[1])) {
                $user_id = $this->resolveUserId($parts[1]);
            } elseif ($reply_user_id) {
                $user_id = $reply_user_id;
            } else {
                $this->sendMessage($peer_id, "❌ Использование: /removemoder @упоминание или ответьте на сообщение пользователя");
                break;
            }
            
            if (!isset($this->config['moderator_ids']) || !in_array(intval($user_id), $this->config['moderator_ids'])) {
                $this->sendMessage($peer_id, "ℹ️ Этот пользователь не является модератором");
                break;
            }
            
            $moderator_ids = $this->config['moderator_ids'];
            $key = array_search(intval($user_id), $moderator_ids);
            if ($key !== false) {
                unset($moderator_ids[$key]);
                $this->config['moderator_ids'] = array_values($moderator_ids);
                
                $config_file = __DIR__ . '/config.php';
                $config_content = file_get_contents($config_file);
                
                $moderator_ids_str = var_export($this->config['moderator_ids'], true);
                $config_content = preg_replace(
                    "/('moderator_ids'\s*=>\s*)array\s*\([^)]*\)/",
                    "$1$moderator_ids_str",
                    $config_content
                );
                
                file_put_contents($config_file, $config_content);
                
                $user_mention = $this->getUserMention($user_id);
                $this->sendMessage($peer_id, "✅ Пользователь {$user_mention} удален из списка модераторов");
            }
            break;
            
        case '!moders':
        case '/moders':
            $moderator_ids = $this->config['moderator_ids'] ?? [];
            if (empty($moderator_ids)) {
                $this->sendMessage($peer_id, "ℹ️ Список модераторов пуст");
                break;
            }
            
            $message = "🛡️ Список модераторов:\n\n";
            
            $nicknames = file_exists($this->config['nicknames_file']) ? 
                json_decode(file_get_contents($this->config['nicknames_file']), true) : [];
            
            foreach ($moderator_ids as $moderator_id) {
                $moderator_mention = $this->getUserMention($moderator_id);
                
                $nickname = "";
                if (isset($nicknames[$peer_id][$moderator_id])) {
                    $nickname = " (" . $nicknames[$peer_id][$moderator_id] . ")";
                }
                
                $message .= "🛡️ {$moderator_mention}{$nickname}\n";
            }
            
            $this->sendMessage($peer_id, $message);
            break;
        
                                case '!help':
                                    case '/help':
                                        if ($this->isAdmin($from_id)) {
                                            $help_message = "📋 Доступные команды:\n\n" .
                                                            "--- Модерация ---\n" .
                                                            "/mute @упоминание [время] - заглушить пользователя\n" .
                                                            "/unmute @упоминание - снять заглушку\n" .
                                                            "/ban @упоминание - заблокировать пользователя\n" .
                                                            "/unban @упоминание - разблокировать пользователя\n" .
                                                            "/kick @упоминание - исключить пользователя\n" .
                                                            "/warn @упоминание [причина] - выдать предупреждение\n" .
                                                            "/unwarn @упоминание - снять предупреждение\n" .
                                                            "/nick @упоминание [никнейм] - установить никнейм\n" .
                                                            "/stats [@упоминание] - статистика пользователя\n\n" .
                                                            
                                                            "--- Администрирование ---\n" .
                                                            "/unite - включить объединение чатов\n" .
                                                            "/separate - отключить объединение чатов\n" .
                                                            "/unified - список объединённых бесед\n" .
                                                            "/addadmin @упоминание - добавить администратора\n" .
                                                            "/removeadmin @упоминание - удалить администратора\n" .
                                                            "/admins - список администраторов\n" .
                                                            "/addmoder @упоминание - добавить модератора\n" .
                                                            "/removemoder @упоминание - удалить модератора\n" .
                                                            "/moders - список модераторов\n\n" .
                                                            
                                                            "Примечание: Все команды модерации также работают при ответе на сообщение пользователя.";
                                        } elseif ($this->isModerator($from_id)) {
                                            $help_message = "📋 Доступные команды:\n\n" .
                                                            "--- Модерация ---\n" .
                                                            "/mute @упоминание [время] - заглушить пользователя\n" .
                                                            "/unmute @упоминание - снять заглушку\n" .
                                                            "/ban @упоминание - заблокировать пользователя\n" .
                                                            "/unban @упоминание - разблокировать пользователя\n" .
                                                            "/kick @упоминание - исключить пользователя\n" .
                                                            "/warn @упоминание [причина] - выдать предупреждение\n" .
                                                            "/unwarn @упоминание - снять предупреждение\n" .
                                                            "/nick @упоминание [никнейм] - установить никнейм\n" .
                                                            "/stats [@упоминание] - статистика пользователя\n" .
                                                            "/admins - список администраторов\n" .
                                                            "/moders - список модераторов\n\n" .
                                                            
                                                            "Примечание: Все команды модерации также работают при ответе на сообщение пользователя.";
                                        } else {
                                            $help_message = "📋 Доступные команды:\n\n" .
                                                            "/stats - статистика пользователя\n" .
                                                            "/admins - список администраторов\n" .
                                                            "/moders - список модераторов\n" .
                                                            "/help - показать это сообщение\n\n" .
                                                            
                                                            "Примечание: Для использования других команд обратитесь к модератору или администратору.";
                                        }
                                        $this->sendMessage($peer_id, $help_message);
                                        break;
        }
    }
}

$bot = new VKChatBot($config);
$bot->run();