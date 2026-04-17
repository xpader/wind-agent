<?php

namespace App\Libs\Agent\Tools;

use App\Libs\Agent\ToolInterface;

/**
 * 时间工具
 *
 * 获取系统当前时间，支持多种格式和时区
 */
class TimeTool implements ToolInterface
{
    /**
     * 获取工具名称
     */
    public function getName(): string
    {
        return 'time';
    }

    /**
     * 获取工具描述
     */
    public function getDescription(): string
    {
        return '获取系统当前时间和日期，支持多种格式和时区';
    }

    /**
     * 获取参数定义
     */
    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'format' => [
                    'type' => 'string',
                    'description' => '时间格式：default(默认)、iso(ISO 8601)、timestamp(时间戳)、date(仅日期)、time(仅时间)、full(完整格式)',
                    'enum' => ['default', 'iso', 'timestamp', 'date', 'time', 'full'],
                    'default' => 'default'
                ],
                'timezone' => [
                    'type' => 'string',
                    'description' => '时区，例如：Asia/Shanghai、UTC、America/New_York。默认使用系统时区',
                    'default' => ''
                ]
            ],
            'required' => []
        ];
    }

    /**
     * 执行工具
     */
    public function execute(array $arguments): string
    {
        $format = $arguments['format'] ?? 'default';
        $timezone = $arguments['timezone'] ?? '';

        try {
            // 设置时区
            if ($timezone !== '') {
                $timezoneObj = new \DateTimeZone($timezone);
            } else {
                $timezoneObj = new \DateTimeZone(date_default_timezone_get());
            }

            $dateTime = new \DateTime('now', $timezoneObj);

            // 根据格式返回时间
            switch ($format) {
                case 'iso':
                    return $dateTime->format('c'); // ISO 8601 格式

                case 'timestamp':
                    return (string)$dateTime->getTimestamp();

                case 'date':
                    return $dateTime->format('Y-m-d');

                case 'time':
                    return $dateTime->format('H:i:s');

                case 'full':
                    return sprintf(
                        "%s 年 %s 月 %s 日 %s:%s:%s %s",
                        $dateTime->format('Y'),
                        $dateTime->format('n'),
                        $dateTime->format('j'),
                        $dateTime->format('H'),
                        $dateTime->format('i'),
                        $dateTime->format('s'),
                        $timezoneObj->getName()
                    );

                case 'default':
                default:
                    return sprintf(
                        "%s年%s月%s日 %s:%s:%s",
                        $dateTime->format('Y'),
                        $dateTime->format('n'),
                        $dateTime->format('j'),
                        $dateTime->format('H'),
                        $dateTime->format('i'),
                        $dateTime->format('s')
                    );
            }
        } catch (\Exception $e) {
            return "获取时间失败： " . $e->getMessage();
        }
    }

    /**
     * 转换为数组格式
     */
    public function toArray(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->getName(),
                'description' => $this->getDescription(),
                'parameters' => $this->getParameters()
            ]
        ];
    }
}
