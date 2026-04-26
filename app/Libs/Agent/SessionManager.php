<?php

namespace App\Libs\Agent;

use function basename;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function glob;
use function is_dir;
use function mkdir;

/**
 * 会话管理器
 *
 * 负责会话的创建、加载、保存和删除
 */
class SessionManager
{
    private static string $sessionsDir;

    /**
     * 初始化会话管理器
     */
    private static function init(): void
    {
        self::$sessionsDir = BASE_DIR . '/workspace/sessions';
        self::ensureDirectory();
    }

    /**
     * 确保会话目录存在
     */
    private static function ensureDirectory(): void
    {
        if (!is_dir(self::$sessionsDir)) {
            mkdir(self::$sessionsDir, 0755, true);
        }

        // 创建 .gitkeep 文件确保目录被 Git 追踪
        $gitkeep = self::$sessionsDir . '/.gitkeep';
        if (!file_exists($gitkeep)) {
            file_put_contents($gitkeep, '');
        }
    }

    /**
     * 生成 UUID v4
     */
    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * 获取会话文件路径
     */
    public static function getSessionPath(string $sessionId): string
    {
        return self::$sessionsDir . '/' . $sessionId . '.jsonl';
    }

    /**
     * 创建新会话
     *
     * @param array $metadata 会话元数据
     * @return string 会话 ID
     */
    public static function create(array $metadata): string
    {
        self::init();

        $sessionId = self::generateUuid();
        $sessionPath = self::getSessionPath($sessionId);

        $now = date('c');
        $metadata['session_id'] = $sessionId;
        $metadata['created_at'] = $now;
        $metadata['updated_at'] = $now;
        $metadata['message_count'] = 0;

        // 写入元数据行
        $metaLine = json_encode([
            'type' => 'meta',
            ...$metadata,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        file_put_contents($sessionPath, $metaLine . "\n");

        return $sessionId;
    }

    /**
     * 加载会话
     *
     * @param string $sessionId 会话 ID
     * @return Session|null 会话对象,不存在时返回 null
     */
    public static function load(string $sessionId): ?Session
    {
        self::init();

        $sessionPath = self::getSessionPath($sessionId);

        if (!file_exists($sessionPath)) {
            return null;
        }

        $lines = file($sessionPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || count($lines) === 0) {
            return null;
        }

        // 解析元数据行
        $metaData = json_decode($lines[0], true);
        if ($metaData === null || $metaData['type'] !== 'meta') {
            return null;
        }

        $metadata = $metaData;
        unset($metadata['type']);

        // 解析消息行
        $messages = [];
        for ($i = 1; $i < count($lines); $i++) {
            $messageData = json_decode($lines[$i], true);
            if ($messageData !== null && $messageData['type'] === 'message') {
                $message = $messageData;
                unset($message['type']);
                $messages[] = $message;
            }
        }

        return new Session($sessionId, $metadata, $messages);
    }

    /**
     * 保存消息到会话
     *
     * @param string $sessionId 会话 ID
     * @param array $message 消息数据
     */
    public static function saveMessage(string $sessionId, array $message): void
    {
        self::init();

        $sessionPath = self::getSessionPath($sessionId);

        if (!file_exists($sessionPath)) {
            return;
        }

        // 追加消息行
        $messageLine = json_encode([
            'type' => 'message',
            ...$message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        file_put_contents($sessionPath, $messageLine . "\n", FILE_APPEND);

        // 更新元数据中的消息数量和更新时间
        self::updateMetadata($sessionId, [
            'updated_at' => date('c'),
        ]);
    }

    /**
     * 更新会话元数据
     *
     * @param string $sessionId 会话 ID
     * @param array $metadata 要更新的元数据
     */
    public static function updateMetadata(string $sessionId, array $metadata): void
    {
        self::init();

        $sessionPath = self::getSessionPath($sessionId);

        if (!file_exists($sessionPath)) {
            return;
        }

        // 读取所有行
        $lines = file($sessionPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || count($lines) === 0) {
            return;
        }

        // 更新元数据行
        $metaData = json_decode($lines[0], true);
        if ($metaData !== null && $metaData['type'] === 'meta') {
            $metaData = array_merge($metaData, $metadata);

            // 更新消息数量
            $messageCount = count($lines) - 1; // 减去元数据行
            $metaData['message_count'] = $messageCount;

            $metaLine = json_encode($metaData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $lines[0] = $metaLine;

            // 重写整个文件
            file_put_contents($sessionPath, implode("\n", $lines) . "\n");
        }
    }

    /**
     * 更新会话标题
     *
     * @param string $sessionId 会话 ID
     * @param string $title 会话标题
     */
    public static function updateTitle(string $sessionId, string $title): void
    {
        self::updateMetadata($sessionId, ['title' => $title]);
    }

    /**
     * 检查会话是否存在
     *
     * @param string $sessionId 会话 ID
     * @return bool
     */
    public static function exists(string $sessionId): bool
    {
        self::init();
        return file_exists(self::getSessionPath($sessionId));
    }

    /**
     * 列出所有会话
     *
     * @return array 会话列表,每个元素包含 session_id, created_at, updated_at, message_count
     */
    public static function listAll(): array
    {
        self::init();

        $sessions = [];
        $files = glob(self::$sessionsDir . '/*.jsonl');

        if ($files === false) {
            return $sessions;
        }

        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false || count($lines) === 0) {
                continue;
            }

            $metaData = json_decode($lines[0], true);
            if ($metaData !== null && $metaData['type'] === 'meta') {
                $sessions[] = [
                    'session_id' => $metaData['session_id'] ?? basename($file, '.jsonl'),
                    'created_at' => $metaData['created_at'] ?? '',
                    'updated_at' => $metaData['updated_at'] ?? '',
                    'model' => $metaData['model'] ?? '',
                    'message_count' => $metaData['message_count'] ?? 0,
                    'title' => $metaData['title'] ?? '',
                ];
            }
        }

        // 按更新时间倒序排序
        usort($sessions, function($a, $b) {
            return strtotime($b['updated_at']) <=> strtotime($a['updated_at']);
        });

        return $sessions;
    }

    /**
     * 删除会话
     *
     * @param string $sessionId 会话 ID
     */
    public static function delete(string $sessionId): void
    {
        self::init();

        $sessionPath = self::getSessionPath($sessionId);

        if (file_exists($sessionPath)) {
            unlink($sessionPath);
        }
    }
}
