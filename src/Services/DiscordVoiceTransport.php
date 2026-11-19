<?php

namespace HrManager\Services;

/**
 * The two Discord REST calls voice tracking makes. An interface so the poller
 * can be driven against a stand-in Discord in the tests.
 */
interface DiscordVoiceTransport
{
    /**
     * GET /guilds/{guild}/voice-states/{user}.
     *
     * @return array{status:int, headers:array<string,string>, body:?array}
     *         headers keyed in lower case; status 0 when no response came back
     */
    public function voiceState(string $guildId, string $discordUserId): array;

    /**
     * GET /guilds/{guild}/channels, as channel id => name.
     *
     * @return array<string, string>
     */
    public function channelNames(string $guildId): array;
}
