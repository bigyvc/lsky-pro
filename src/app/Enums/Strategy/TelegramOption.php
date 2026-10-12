<?php

namespace App\Enums\Strategy;

final class TelegramOption
{
    /** @var string 访问url（直链模式用；Telegram 策略必须配合原图保护使用，此处仅作占位） */
    const Url = 'url';

    /** @var string Bot Token（@BotFather 获取，绝不能暴露给前端） */
    const BotToken = 'bot_token';

    /** @var string 频道 Chat ID（bot 必须是该频道的管理员） */
    const ChatId = 'chat_id';

    /** @var string Bot API 地址（默认官方，可填自建 Bot API 服务） */
    const ApiBase = 'api_base';
}
