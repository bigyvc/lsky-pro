<?php

namespace App\Enums\Strategy;

final class TelegramOption
{
    /** @var string 图床访问域名（TG 图片始终走应用输出，不暴露 Bot Token） */
    const Url = 'url';

    /** @var string Bot Token（@BotFather 获取，绝不能暴露给前端） */
    const BotToken = 'bot_token';

    /** @var string 频道 Chat ID（bot 必须是该频道的管理员） */
    const ChatId = 'chat_id';

    /** @var string Bot API 地址（默认官方，可填自建 Bot API 服务） */
    const ApiBase = 'api_base';
}
