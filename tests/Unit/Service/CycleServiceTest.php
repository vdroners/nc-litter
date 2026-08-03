<?php

declare(strict_types=1);

namespace OCA\NcLitter\Tests\Unit\Service;

use OCA\NcLitter\Db\Cycle;
use OCA\NcLitter\Db\TelemetrySample;
use OCA\NcLitter\Service\AdminSecretCrypto;
use OCA\NcLitter\Service\AuditService;
use OCA\NcLitter\Service\CycleService;
use OCA\NcLitter\Service\DeviceService;
use OCA\NcLitter\Service\ErrorDecoderService;
use OCA\NcLitter\Service\MaintenanceHintService;
use OCA\NcLitter\Tests\Support\FakeAppData;
use OCA\NcLitter\Tests\Support\FakeBridgeClient;
use OCA\NcLitter\Tests\Support\FakeCommandAuditMapper;
use OCA\NcLitter\Tests\Support\FakeConfig;
use OCA\NcLitter\Tests\Support\FakeCrypto;
use OCA\NcLitter\Tests\Support\FakeCycleEventMapper;
use OCA\NcLitter\Tests\Support\FakeCycleMapper;
use OCA\NcLitter\Tests\Support\FakeDeviceMapper;
use OCA\NcLitter\Tests\Support\FakeNotifyService;
use OCA\NcLitter\Tests\Support\FakeTelemetrySampleMapper;
use OCA\NcLitter\Tests\Support\FakeTempManager;
use OCA\NcLitter\Tests\Support\NullLogger;
use PHPUnit\Framework\TestCase;

use function OCA\NcLitter\Tests\Support\catalogPath;
use function OCA\NcLitter\Tests\Support\liveStateDto;
use function OCA\NcLitter\Tests\Support\makeDevice;

/**
 * The cycle state machine, the reaper, edge-triggered notifications and
 * retention. Every case here corresponds to a defect observed on the live
 * instance, so the names describe the wrong behaviour being prevented.
 */
class CycleServiceTest extends TestCase
{
	private const DEVICE = 1;

	/** CycleService::MAX_CYCLE_S */
	private const MAX_CYCLE_S = 900;

	/** CycleService::PLAUSIBLE_CYCLE_S */
	private const PLAUSIBLE_CYCLE_S = 180;

	private FakeCycleMapper $cycles;
	private FakeCycleEventMapper $events;
	private FakeTelemetrySampleMapper $telemetry;
	private FakeCommandAuditMapper $audit;
	private FakeNotifyService $notify;
	private CycleService $svc;

	protected function setUp(): void
	{
		$this->cycles = new FakeCycleMapper();
		$this->events = new FakeCycleEventMapper();
		$this->telemetry = new FakeTelemetrySampleMapper();
		$this->audit = new FakeCommandAuditMapper();
		$this->notify = new FakeNotifyService();

		$devices = new DeviceService(
			new FakeDeviceMapper([self::DEVICE => makeDevice(self::DEVICE, 'Poop Roller')]),
			new FakeBridgeClient(),
			new AdminSecretCrypto(new FakeCrypto(), new NullLogger()),
			new ErrorDecoderService(catalogPath('error_codes.json')),
			new MaintenanceHintService(catalogPath('maintenance_thresholds.json')),
			new AuditService($this->audit),
			new FakeConfig(),
			new FakeTempManager(),
			new FakeAppData(),
		);

		$this->svc = new CycleService(
			$this->cycles,
			$this->events,
			$this->telemetry,
			$this->audit,
			new ErrorDecoderService(catalogPath('error_codes.json')),
			$this->notify,
			$devices,
		);
	}

	/** @param array<string,mixed> $overrides */
	private function ingest(array $overrides): void
	{
		$this->svc->ingestState(self::DEVICE, liveStateDto($overrides));
	}

	/** Seed a stored sample so the next tick has a `prev` to compare against. */
	private function seedSample(int $ts, ?int $cycleCount, ?int $drawer = 7, ?string $status = 'ready'): TelemetrySample
	{
		$sample = new TelemetrySample();
		$sample->setDeviceId(self::DEVICE);
		$sample->setCycleId(null);
		$sample->setTs($ts);
		$sample->setStatus($status);
		$sample->setDrawerLevelPct($drawer);
		$sample->setLitterLevelPct(90);
		$sample->setCycleCount($cycleCount);
		$sample->setErrorCode(0);
		return $this->telemetry->insert($sample);
	}

	/** Seed an open cycle that started `$ageS` seconds ago. */
	private function seedOpenCycle(int $ageS): Cycle
	{
		$cycle = new Cycle();
		$cycle->setDeviceId(self::DEVICE);
		$cycle->setStartedAt(time() - $ageS);
		$cycle->setEndedAt(null);
		$cycle->setResult('open');
		$cycle->setErrorCode(0);
		$cycle->setTrigger('auto');
		$cycle->setDrawerBefore(7);
		$cycle->setCreatedAt(time() - $ageS);
		return $this->cycles->insert($cycle);
	}

	// ── Opening and holding ──────────────────────────────────────────────────

	public function testOpensOnCleaning(): void
	{
		$this->ingest(['status' => 'cleaning', 'status_code' => 'CCP']);
		$this->assertCount(1, $this->cycles->rows);
		$open = $this->cycles->findOpenCycle(self::DEVICE);
		$this->assertNotNull($open);
		$this->assertSame('open', $open->getResult());
		$this->assertSame(7, $open->getDrawerBefore());
	}

	public function testOpensOnEmptying(): void
	{
		$this->ingest(['status' => 'emptying', 'status_code' => 'EC']);
		$this->assertNotNull($this->cycles->findOpenCycle(self::DEVICE));
	}

	/** `paused` is a mid-cycle hold: the open cycle must survive it untouched. */
	public function testHoldsOnPaused(): void
	{
		$this->ingest(['status' => 'cleaning', 'status_code' => 'CCP']);
		$this->ingest(['status' => 'paused', 'status_code' => 'P']);
		$this->assertCount(1, $this->cycles->rows);
		$this->assertNotNull($this->cycles->findOpenCycle(self::DEVICE));
	}

	/** `offline` says nothing about the cycle, so it must not close one. */
	public function testOpaqueStatusDoesNotCloseACycle(): void
	{
		$this->ingest(['status' => 'cleaning', 'status_code' => 'CCP']);
		$this->ingest(['status' => 'offline', 'status_code' => 'OFFLINE']);
		$this->assertNotNull($this->cycles->findOpenCycle(self::DEVICE));
	}

	public function testDoesNotOpenASecondCycleWhileOneIsOpen(): void
	{
		$this->ingest(['status' => 'cleaning', 'status_code' => 'CCP']);
		$this->ingest(['status' => 'cleaning', 'status_code' => 'CCP']);
		$this->assertCount(1, $this->cycles->rows);
	}

	// ── The reaper ───────────────────────────────────────────────────────────

	/**
	 * THE bug: the reaper closed an over-age cycle and the same tick reopened one
	 * from the same stale reading, chaining fabricated `interrupted` rows
	 * end-to-start. Seven of them were sitting in the live database.
	 */
	public function testDoesNotReopenOnAReapTick(): void
	{
		$this->seedOpenCycle(self::MAX_CYCLE_S + 1);
		$this->ingest(['status' => 'cleaning', 'status_code' => 'CCP']);

		$this->assertCount(1, $this->cycles->rows, 'the reap must not spawn a replacement');
		$this->assertNull($this->cycles->findOpenCycle(self::DEVICE));
		$reaped = $this->cycles->rows[1];
		$this->assertSame('interrupted', $reaped->getResult());
	}

	public function testRepeatedReapTicksNeverChainRows(): void
	{
		$this->seedOpenCycle(self::MAX_CYCLE_S + 1);
		for ($i = 0; $i < 5; $i++) {
			$this->ingest(['status' => 'cleaning', 'status_code' => 'CCP']);
		}
		// Tick 1 reaps. Tick 2 opens (nothing was reaped that tick). Ticks 3-5 see a
		// young open cycle and leave it alone. Two rows total, never a chain.
		$this->assertCount(2, $this->cycles->rows);
		foreach ($this->cycles->rows as $cycle) {
			if ($cycle->getResult() === 'interrupted') {
				$this->assertNull($cycle->getDurationS(), 'no interrupted row may carry a duration');
			}
		}
	}

	/**
	 * A reaped cycle never saw its end, so it gets no duration and no terminal
	 * sensor readings. The live rows carried durations of 901-1801 s against a
	 * 900 s reap threshold — arithmetically impossible.
	 */
	public function testReapedCycleRecordsNoDurationAndNoTerminalReadings(): void
	{
		$this->seedOpenCycle(1500);
		$this->ingest(['status' => 'ready', 'status_code' => 'RDY']);

		$reaped = $this->cycles->rows[1];
		$this->assertSame('interrupted', $reaped->getResult());
		$this->assertNull($reaped->getDurationS(), 'a poll gap is not a duration');
		$this->assertNull($reaped->getDrawerAfter());
		$this->assertNull($reaped->getCatWeight());
	}

	// ── Durations ────────────────────────────────────────────────────────────

	/** A close observed promptly is a real measurement and is kept. */
	public function testMeasuredDurationIsRecorded(): void
	{
		$open = $this->seedOpenCycle(30);
		$this->ingest(['status' => 'ready', 'status_code' => 'RDY']);

		$closed = $this->cycles->rows[(int) $open->getId()];
		$this->assertSame('complete', $closed->getResult());
		$this->assertNotNull($closed->getDurationS());
		$this->assertLessThanOrEqual(self::PLAUSIBLE_CYCLE_S, (int) $closed->getDurationS());
	}

	/**
	 * With cron at 5 minutes the gap between the opening and closing sample is the
	 * poll interval, not the cycle. The live `result=complete` row claimed
	 * `duration_s=900`, and that number was announced to operators.
	 */
	public function testPollGapIsNotRecordedAsADuration(): void
	{
		$open = $this->seedOpenCycle(self::PLAUSIBLE_CYCLE_S + 60);
		$this->ingest(['status' => 'ready', 'status_code' => 'RDY']);

		$closed = $this->cycles->rows[(int) $open->getId()];
		$this->assertSame('complete', $closed->getResult());
		$this->assertNull($closed->getDurationS());
	}

	public function testNotificationCarriesNullDurationWhenNothingWasTimed(): void
	{
		$this->seedOpenCycle(self::PLAUSIBLE_CYCLE_S + 60);
		$this->ingest(['status' => 'ready', 'status_code' => 'RDY']);

		$this->assertContains('cycle_complete', $this->notify->kinds());
		$complete = array_values(array_filter(
			$this->notify->sent,
			static fn (array $n) => $n['kind'] === 'cycle_complete',
		))[0];
		$this->assertNull($complete['duration_s'], 'never quote a poll gap as a cycle length');
	}

	// ── cycle_count deltas ───────────────────────────────────────────────────

	/**
	 * A ~90 s LR4 cycle is essentially never caught in the act by a 300-900 s
	 * sampler, so the odometer — not a sighting of `cleaning` — is the evidence
	 * that a cycle ran.
	 */
	public function testCycleCountDeltaRecordsAnUnobservedCycle(): void
	{
		$this->seedSample(time() - 300, 1684, 7);
		$this->ingest(['status' => 'ready', 'status_code' => 'RDY', 'cycle_count' => 1685, 'drawer_level_pct' => 9]);

		$this->assertCount(1, $this->cycles->rows);
		$cycle = $this->cycles->rows[1];
		$this->assertSame('complete', $cycle->getResult());
		$this->assertNull($cycle->getDurationS(), 'neither boundary was observed');
		$this->assertNotNull($cycle->getEndedAt());
		$this->assertSame(7, $cycle->getDrawerBefore());
		$this->assertSame(9, $cycle->getDrawerAfter());
		$this->assertContains('cycle_complete', $this->notify->kinds());
	}

	public function testNoDeltaRecordsNothing(): void
	{
		$this->seedSample(time() - 300, 1684);
		$this->ingest(['status' => 'ready', 'status_code' => 'RDY', 'cycle_count' => 1684]);
		$this->assertSame([], $this->cycles->rows);
		$this->assertSame([], $this->notify->kinds());
	}

	/** A counter that went backwards was reset; that is not a cycle. */
	public function testCounterResetRecordsNothing(): void
	{
		$this->seedSample(time() - 300, 1684);
		$this->ingest(['status' => 'ready', 'status_code' => 'RDY', 'cycle_count' => 3]);
		$this->assertSame([], $this->cycles->rows);
	}

	public function testFirstEverSampleRecordsNothing(): void
	{
		$this->ingest(['status' => 'ready', 'status_code' => 'RDY', 'cycle_count' => 1684]);
		$this->assertSame([], $this->cycles->rows, 'with no previous sample there is no delta');
		$this->assertCount(1, $this->telemetry->rows);
	}

	/** An observed cycle and the delta that follows it must not both be recorded. */
	public function testDeltaDoesNotDoubleCountAnObservedCycle(): void
	{
		$this->ingest(['status' => 'cleaning', 'status_code' => 'CCP', 'cycle_count' => 1684]);
		$this->ingest(['status' => 'ready', 'status_code' => 'RDY', 'cycle_count' => 1685]);
		$this->assertCount(1, $this->cycles->rows);
	}

	public function testDeltaIsNotRecordedOnAReapTick(): void
	{
		$this->seedOpenCycle(self::MAX_CYCLE_S + 1);
		$this->seedSample(time() - 300, 1684);
		$this->ingest(['status' => 'ready', 'status_code' => 'RDY', 'cycle_count' => 1690]);
		$this->assertCount(1, $this->cycles->rows, 'the reaped row is the only one');
	}

	public function testFaultDuringAnUnobservedCycleNotifiesAsAFault(): void
	{
		$this->seedSample(time() - 300, 1684);
		$this->ingest([
			'status' => 'fault',
			'status_code' => 'BR',
			'error' => 1,
			'cycle_count' => 1685,
		]);
		$this->assertContains('cycle_fault', $this->notify->kinds());
		$fault = array_values(array_filter(
			$this->notify->sent,
			static fn (array $n) => $n['kind'] === 'cycle_fault',
		))[0];
		$this->assertSame('BR', $fault['error_code']);
		$this->assertStringContainsString('bonnet', strtolower((string) $fault['title']));
	}

	// ── Telemetry and events ─────────────────────────────────────────────────

	/** An LR4 has no RSSI; the sample must not pretend to carry one. */
	public function testSamplesDoNotCarryAnRssi(): void
	{
		$this->ingest(['status' => 'ready', 'status_code' => 'RDY']);
		$row = $this->telemetry->rows[0]->jsonSerialize();
		$this->assertArrayNotHasKey('rssi', $row);
	}

	public function testSamplePayloadKeepsTheNewDtoFieldsAndDropsTheOldOnes(): void
	{
		$this->ingest(['status' => 'ready', 'status_code' => 'RDY']);
		$payload = json_decode((string) $this->telemetry->rows[0]->getPayloadJson(), true);
		$this->assertSame('RDY', $payload['status_code']);
		$this->assertSame(0, $payload['cycles_since_full']);
		$this->assertSame('OPTIMAL', $payload['litter_level_state']);
		$this->assertSame('OFF', $payload['wifi_mode']);
		$this->assertArrayNotHasKey('wifi_ssid', $payload);
	}

	public function testPhaseEventsAppendOnlyOnAChange(): void
	{
		$this->ingest(['status' => 'cleaning', 'status_code' => 'CCP']);
		$this->ingest(['status' => 'cleaning', 'status_code' => 'CCP']);
		$this->assertCount(1, $this->events->rows);
		$this->ingest(['status' => 'paused', 'status_code' => 'P']);
		$this->assertCount(2, $this->events->rows);
	}

	// ── Level notifications ──────────────────────────────────────────────────

	/** Rising edge only: the sampler runs in a fresh process, so latches must be in the data. */
	public function testDrawerFullNotifiesOnceOnTheRisingEdge(): void
	{
		$this->ingest(['status' => 'drawer_full', 'status_code' => 'DFS', 'drawer_level_pct' => 99]);
		$this->assertSame(1, count(array_filter(
			$this->notify->sent,
			static fn (array $n) => $n['kind'] === 'drawer_full',
		)));

		$this->ingest(['status' => 'drawer_full', 'status_code' => 'DFS', 'drawer_level_pct' => 99]);
		$this->assertSame(1, count(array_filter(
			$this->notify->sent,
			static fn (array $n) => $n['kind'] === 'drawer_full',
		)), 'a standing condition must not re-alert every poll');
	}

	public function testDrawerFullNotifiesAgainAfterItClears(): void
	{
		$this->ingest(['status' => 'drawer_full', 'status_code' => 'DFS', 'drawer_level_pct' => 99]);
		$this->ingest(['status' => 'ready', 'status_code' => 'RDY', 'drawer_level_pct' => 4]);
		$this->ingest(['status' => 'drawer_full', 'status_code' => 'DFS', 'drawer_level_pct' => 99]);
		$this->assertSame(2, count(array_filter(
			$this->notify->sent,
			static fn (array $n) => $n['kind'] === 'drawer_full',
		)));
	}

	public function testLitterLowNotifiesOnTheRisingEdgeOnly(): void
	{
		$this->ingest(['status' => 'ready', 'status_code' => 'RDY', 'litter_level_pct' => 5]);
		$this->ingest(['status' => 'ready', 'status_code' => 'RDY', 'litter_level_pct' => 4]);
		$this->assertSame(1, count(array_filter(
			$this->notify->sent,
			static fn (array $n) => $n['kind'] === 'litter_low',
		)));
	}

	public function testHealthyLevelsNotifyNothing(): void
	{
		$this->ingest(['status' => 'ready', 'status_code' => 'RDY']);
		$this->assertSame([], $this->notify->kinds());
	}

	// ── Listing ──────────────────────────────────────────────────────────────

	/** `?limit=2` used to answer `total: 2` with eight rows in the table. */
	public function testListCyclesReportsTheTrueTotalNotThePageSize(): void
	{
		for ($i = 0; $i < 8; $i++) {
			$cycle = $this->seedOpenCycle(10_000 - $i * 100);
			$cycle->setEndedAt((int) $cycle->getStartedAt() + 60);
			$cycle->setResult('complete');
			$this->cycles->update($cycle);
		}
		$page = $this->svc->listCycles(self::DEVICE, 2, 0);
		$this->assertCount(2, $page['items']);
		$this->assertSame(8, $page['total']);
	}

	// ── Retention ────────────────────────────────────────────────────────────

	/**
	 * Retention 0 used to compute a cutoff of `time() + 1`, so a dry run on the
	 * live instance offered to delete everything — including the telemetry sample
	 * written seconds earlier and the audit row for the command still in flight.
	 */
	public function testZeroRetentionCannotDeleteWhatJustHappened(): void
	{
		$this->ingest(['status' => 'ready', 'status_code' => 'RDY']);
		$this->seedSample(time() - 10, 1684);

		$preview = $this->svc->retentionDryRun(0);
		$this->assertLessThanOrEqual(time() - 3600, $preview['cutoff'], 'the cutoff must never reach the present');
		$this->assertSame(0, $preview['telemetry']);

		$applied = $this->svc->retentionApply(0);
		$this->assertSame(0, $applied['telemetry']);
		$this->assertNotSame([], $this->telemetry->rows);
	}

	public function testZeroRetentionStillPrunesGenuinelyOldRows(): void
	{
		$this->seedSample(time() - 7200, 1600);
		$this->assertSame(1, $this->svc->retentionDryRun(0)['telemetry']);
		$this->assertSame(1, $this->svc->retentionApply(0)['telemetry']);
		$this->assertSame([], $this->telemetry->rows);
	}

	/**
	 * `cycles.deleteOlderThan` protects an open cycle but the telemetry delete had
	 * no such guard, so the retained cycle survived with its chart emptied.
	 */
	public function testAnOpenCyclesTelemetrySurvivesRetention(): void
	{
		$open = $this->seedOpenCycle(20_000);
		$sample = $this->seedSample(time() - 20_000, 1600);
		$sample->setCycleId((int) $open->getId());
		$this->telemetry->update($sample);

		$this->svc->retentionApply(0);

		$this->assertArrayHasKey((int) $open->getId(), $this->cycles->rows, 'an open cycle is never pruned');
		$this->assertCount(1, $this->telemetry->findByCycle((int) $open->getId()));
	}

	/** Cycles, their phase events and their telemetry are pruned as one batch. */
	public function testRetentionPrunesCyclesEventsAndTelemetryTogether(): void
	{
		$old = $this->seedOpenCycle(20_000);
		$old->setEndedAt(time() - 19_000);
		$old->setResult('complete');
		$this->cycles->update($old);

		$sample = $this->seedSample(time() - 19_500, 1600);
		$sample->setCycleId((int) $old->getId());
		$this->telemetry->update($sample);

		$event = new \OCA\NcLitter\Db\CycleEvent();
		$event->setCycleId((int) $old->getId());
		$event->setDeviceId(self::DEVICE);
		$event->setTs(time() - 19_500);
		$event->setStatus('cleaning');
		$event->setSource('telemetry');
		$this->events->insert($event);

		$result = $this->svc->retentionApply(0);

		$this->assertSame(1, $result['cycles']);
		$this->assertSame([], $this->cycles->rows);
		$this->assertSame([], $this->events->rows, 'no orphaned phase events');
		$this->assertSame([], $this->telemetry->rows);
	}

	public function testRetentionDryRunDoesNotMutate(): void
	{
		$this->seedSample(time() - 7200, 1600);
		$before = count($this->telemetry->rows);
		$this->svc->retentionDryRun(0);
		$this->assertCount($before, $this->telemetry->rows);
	}
}
