<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../MELCloudConnection/MELCloudDataTools.php';

final class ATADataToolsTest extends TestCase
{
    public function testCorruptEnergyValueIsRejectedAndFirstPollSeedsHistory(): void
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/fixtures/ata-energy.json'), true, 512, JSON_THROW_ON_ERROR);
        $now = new DateTimeImmutable('2020-01-02T10:30:00Z');
        $entries = MELCloudDataTools::parseEnergyEntries($data, $now);

        self::assertCount(2, $entries);
        $first = MELCloudDataTools::updateEnergyState([], $entries, $now->getTimestamp());
        self::assertSame(0.0, $first['deltaKWh']);
        self::assertSame(3.0, $first['rolling24hKWh']);

        $nextData = $data;
        $nextData['measureData'][0]['values'][0]['value'] = 1300;
        $nextEntries = MELCloudDataTools::parseEnergyEntries($nextData, $now);
        $second = MELCloudDataTools::updateEnergyState($first['state'], $nextEntries, $now->getTimestamp());
        self::assertSame(0.1, $second['deltaKWh']);
        self::assertSame(0.1, $second['state']['totalKWh']);
    }

    public function testOutdoorUsesLatestRealReadingAndSkipsSyntheticHour(): void
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/fixtures/ata-outdoor.json'), true, 512, JSON_THROW_ON_ERROR);
        $reading = MELCloudDataTools::parseOutdoorReading($data, 'Europe/Berlin', (new DateTimeImmutable('2020-01-02T10:30:00Z'))->getTimestamp());

        self::assertNotNull($reading);
        self::assertSame(12.5, $reading['value']);
        self::assertSame('2020-01-02T07:17:00+00:00', (new DateTimeImmutable('@' . $reading['recordedAt']))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));
    }

    public function testWifiSignalUsesNewestTelemetryReadingAndAcceptsSecondZero(): void
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/fixtures/ata-wifi.json'), true, 512, JSON_THROW_ON_ERROR);
        $now = new DateTimeImmutable('2020-01-02T10:30:00Z');
        $reading = MELCloudDataTools::parseWifiSignalReading($data, $now);

        self::assertNotNull($reading);
        self::assertSame(-48, $reading['value']);
        self::assertSame('2020-01-02T09:20:00+00:00', (new DateTimeImmutable('@' . $reading['recordedAt']))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));
    }

    public function testWifiSignalReturnsNullForEmptyOrUnusableTelemetry(): void
    {
        $now = new DateTimeImmutable('2020-01-02T10:30:00Z');

        self::assertNull(MELCloudDataTools::parseWifiSignalReading([], $now));
        self::assertNull(MELCloudDataTools::parseWifiSignalReading([
            'measureData' => [
                ['type' => 'temperature', 'values' => [['time' => '2020-01-02T09:20:00Z', 'value' => 21.5]]],
                ['type' => 'rssi', 'values' => [['time' => 'not-a-timestamp', 'value' => -48]]]
            ]
        ], $now));
    }
}
