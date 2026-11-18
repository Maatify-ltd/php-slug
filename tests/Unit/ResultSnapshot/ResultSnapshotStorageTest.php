<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\ResultSnapshot;

use Maatify\Slug\Lifecycle\Mapper\ResultSnapshot\ResultSnapshotEncoder;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Lifecycle\DTO\SlugMutationResultDTO;
use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Lifecycle\Repository\Operation\ResultSnapshotMetadata;
use Maatify\Slug\Lifecycle\Mapper\ResultSnapshot\ResultSnapshotStorage;
use Maatify\Slug\Tests\Unit\Contracts\ContractFixtures;
use PHPUnit\Framework\TestCase;

final class ResultSnapshotStorageTest extends TestCase
{
    /**
     * @param list<int|string> $path
     * @param list<int|string>|null $secondPath
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('conflictingResultEvidence')]
    public function testCanonicalStorageRejectsConflictingResultEvidence(string $family, array $path, mixed $replacement, ?array $secondPath = null): void
    {
        $fixture = match ($family) {
            'mutation' => ContractFixtures::mutation(),
            'transition' => ContractFixtures::transition(),
            'transfer' => ContractFixtures::transfer(),
            'alias-transfer' => ContractFixtures::nonCurrentTransfer(RegistryRoleEnum::ACTIVE_ALIAS),
            'adoption' => ContractFixtures::adoption(),
            default => throw new \LogicException('Unknown result fixture.'),
        };
        $key = str_repeat('a', 32);
        $original = str_replace('"operation_key":null', '"operation_key":"' . $key . '"', ResultSnapshotEncoder::encode($fixture));
        $snapshot = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($snapshot);
        $changed = self::replaceSnapshotValue($snapshot, ['result', ...$path], $replacement);
        if ($secondPath !== null) {
            $changed = self::replaceSnapshotValue($changed, ['result', ...$secondPath], $replacement);
        }
        $json = json_encode($changed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $metadata = new ResultSnapshotMetadata($family === 'alias-transfer' ? 'transfer' : $family, 1, $fixture->operationType, $key, $json);

        $this->expectException(\Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException::class);
        $this->expectExceptionMessage('Invalid result snapshot');
        ResultSnapshotStorage::decodeCommitted($metadata, ContractFixtures::registry());
    }

    /** @return iterable<string, array{string, list<int|string>, mixed, 3?: list<int|string>}> */
    public static function conflictingResultEvidence(): iterable
    {
        yield 'assignment reports release all' => ['mutation', ['change_type'], 'RELEASED_ALL'];
        yield 'assignment reports alias history' => ['mutation', ['history_events', 0, 'event_type'], 'ALIAS_ADDED'];
        yield 'assignment history names another slug' => ['mutation', ['history_events', 0, 'slug_snapshot'], 'unrelated-slug'];
        yield 'assignment claims it assigned to an inactive state' => ['mutation', ['after', 'state', 'status'], 'INACTIVE'];
        yield 'current adoption begins with a live Binding' => [
            'adoption', ['before'], ContractFixtures::binding(ContractFixtures::identity(4), 400, 'existing', 500, BindingStatusEnum::ACTIVE, 0),
        ];
        yield 'alias transfer changes the target current claim' => [
            'alias-transfer', ['target_result', 'after', 'state', 'current_slug'], 'unrelated-current', ['target_result', 'after', 'current_claim', 'slug'],
        ];
        yield 'alias transfer changes the source status' => ['alias-transfer', ['source_result', 'after', 'state', 'status'], 'INACTIVE'];
        yield 'transition after state' => ['transition', ['source_result', 'after', 'state', 'status'], 'ACTIVE'];
        yield 'transition destination claim' => ['transition', ['target_claim'], 'different-slug'];
        yield 'transition history omissions' => ['transition', ['history_events'], []];
        yield 'mutation claim owner' => ['mutation', ['affected_claims', 0, 'binding', 'entity', 'entity_key'], 'other-owner'];
        yield 'transfer claim owner' => ['transfer', ['transferred_claim', 'binding', 'entity', 'entity_key'], 'other-owner'];
        yield 'adoption claim owner' => ['adoption', ['adopted_claim', 'binding', 'entity', 'entity_key'], 'other-owner'];
        yield 'adoption history participant' => ['adoption', ['history_event', 'binding_id'], 999];
        yield 'mutation before belongs to another Binding' => ['mutation', ['before'], ContractFixtures::binding(ContractFixtures::identity(99), 999)];
        yield 'mutation history names another entity' => ['mutation', ['history_events', 0, 'entity_snapshot', 'entity_key'], 'other-owner'];
        yield 'transfer target before has a different Binding ID' => ['transfer', ['target_result', 'before', 'id'], 999];
        yield 'transfer target history names another entity' => [
            'transfer', ['target_result', 'history_events', 0, 'entity_snapshot', 'entity_key'],
            'other-owner', ['history_events', 2, 'entity_snapshot', 'entity_key'],
        ];
        yield 'transition repeats a foreign source before snapshot' => [
            'transition', ['source_result', 'before'], ContractFixtures::binding(ContractFixtures::identity(99), 999), ['source_before'],
        ];
        yield 'parallel transition reports a move' => ['transition', ['mode'], 'PARALLEL'];
        yield 'transition reports an existing target' => ['transition', ['target_created'], false];
        yield 'adoption before belongs to another Binding' => ['adoption', ['before'], ContractFixtures::binding(ContractFixtures::identity(99), 999)];
        yield 'adoption history names another entity' => ['adoption', ['history_event', 'entity_snapshot', 'entity_key'], 'other-owner'];
        yield 'current adoption reports an alias event' => ['adoption', ['history_event', 'event_type'], 'ADOPTED_ALIAS'];
        yield 'current adoption reports an alias claim role' => ['adoption', ['adopted_claim', 'role'], 'ACTIVE_ALIAS'];
        yield 'current adoption claim differs from the after current claim' => ['adoption', ['adopted_claim', 'id'], 999];
        yield 'current transfer claim differs from the target current claim' => ['transfer', ['transferred_claim', 'id'], 999];
        yield 'transition target history has a different slug in both copies' => [
            'transition', ['target_result', 'history_events', 0, 'slug_snapshot'], 'different-slug', ['history_events', 1, 'slug_snapshot'],
        ];
        yield 'transition source history has the wrong role in both copies' => [
            'transition', ['source_result', 'history_events', 0, 'claim_role_snapshot'], 'ACTIVE_ALIAS', ['history_events', 0, 'claim_role_snapshot'],
        ];
        yield 'mutation omits newly recorded history' => ['mutation', ['history_events'], []];
        yield 'mutation history sequence skips the recorded position' => ['mutation', ['history_events', 0, 'sequence_no'], 2];
    }

    /**
     * Replaces one existing fixture field while preserving the remainder of the canonical snapshot.
     *
     * @param array<array-key, mixed> $snapshot
     * @param non-empty-list<int|string> $path
     * @return array<array-key, mixed>
     */
    private static function replaceSnapshotValue(array $snapshot, array $path, mixed $value): array
    {
        $key = array_shift($path);
        self::assertArrayHasKey($key, $snapshot);
        if ($path === []) {
            $snapshot[$key] = $value;
        } else {
            $child = $snapshot[$key];
            self::assertIsArray($child);
            $snapshot[$key] = self::replaceSnapshotValue($child, $path, $value);
        }

        return $snapshot;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inconsistentParticipantRevisions')]
    public function testSnapshotRejectsParticipantRevisionsDifferentFromTheBindingState(string $fixtureType, bool $nested): void
    {
        $fixture = $fixtureType === 'transition' ? ContractFixtures::transition() : ContractFixtures::transfer();
        /** @var array{result: array{source_revision: int, target_revision: int, source_result: array{revision: int}}} $snapshot */
        $snapshot = json_decode(ResultSnapshotEncoder::encode($fixture), true, flags: JSON_THROW_ON_ERROR);
        if ($nested) {
            $snapshot['result']['source_result']['revision']++;
        } elseif ($fixtureType === 'transition') {
            $snapshot['result']['source_revision']++;
        } else {
            $snapshot['result']['target_revision']++;
        }

        $this->expectException(\Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException::class);
        $this->expectExceptionMessage('Invalid result snapshot');
        \Maatify\Slug\Lifecycle\Mapper\ResultSnapshot\ResultSnapshotDecoder::decode(
            json_encode($snapshot, JSON_THROW_ON_ERROR),
            ContractFixtures::registry(),
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function inconsistentParticipantRevisions(): iterable
    {
        yield 'transition summary' => ['transition', false];
        yield 'transfer summary' => ['transfer', false];
        yield 'participant revision' => ['transition', true];
    }

    public function testAllFourResultTypesRoundTripThroughCanonicalStorageValidation(): void
    {
        foreach ([
            ['mutation', ContractFixtures::mutation()],
            ['transition', ContractFixtures::transition()],
            ['transfer', ContractFixtures::transfer()],
            ['adoption', ContractFixtures::adoption()],
        ] as $index => [$resultType, $fixture]) {
            $operationKey = str_repeat((string) ($index + 1), 32);
            $snapshot = ResultSnapshotEncoder::encode($fixture);
            $snapshot = str_replace('"operation_key":null', '"operation_key":"' . $operationKey . '"', $snapshot);
            $metadata = new ResultSnapshotMetadata($resultType, 1, $fixture->operationType, $operationKey, $snapshot);
            $decoded = ResultSnapshotStorage::decodeCommitted($metadata, ContractFixtures::registry());

            self::assertTrue($decoded->replayed, $resultType);
            self::assertSame($snapshot, ResultSnapshotEncoder::encode($decoded->withReplayed(false)), $resultType);
        }
    }

    public function testCanonicalStoredSnapshotDecodesAsReplayOnlyInMemory(): void
    {
        $operationKey = str_repeat('a', 32);
        $fixture = ContractFixtures::mutation();
        $event = $fixture->historyEvents[0];
        $history = [new HistoryEventDTO(
            $event->id,
            $event->bindingId,
            $event->sequenceNo,
            $event->eventType,
            $event->scopeSnapshot,
            $event->entitySnapshot,
            $event->slugSnapshot,
            $event->previousSlugSnapshot,
            $event->claimRoleSnapshot,
            $event->previousClaimRoleSnapshot,
            $event->relatedScope,
            $event->relatedEntity,
            $operationKey,
            $event->actorKey,
            $event->reason,
            $event->correlationKey,
            $event->occurredAt,
            $event->originalOccurredAt,
        )];
        $result = new SlugMutationResultDTO(
            $fixture->operationType,
            $operationKey,
            false,
            $fixture->before,
            $fixture->after,
            $fixture->affectedClaims,
            $fixture->previousSlug,
            $fixture->currentSlug,
            $fixture->changeType,
            $fixture->revision,
            $history,
        );
        $snapshot = ResultSnapshotEncoder::encode($result);
        $metadata = new ResultSnapshotMetadata('mutation', 1, $result->operationType, $operationKey, $snapshot);

        $decoded = ResultSnapshotStorage::decodeCommitted($metadata, ContractFixtures::registry());

        self::assertTrue($decoded->replayed);
        self::assertSame($operationKey, $decoded->operationKey);
        self::assertSame($snapshot, ResultSnapshotEncoder::encode($decoded->withReplayed(false)));
    }

    public function testNonCanonicalOrMalformedStoredSnapshotIsRejected(): void
    {
        $operationKey = str_repeat('b', 32);
        $fixture = ContractFixtures::mutation();
        $event = $fixture->historyEvents[0];
        $history = [new HistoryEventDTO(
            $event->id,
            $event->bindingId,
            $event->sequenceNo,
            $event->eventType,
            $event->scopeSnapshot,
            $event->entitySnapshot,
            $event->slugSnapshot,
            $event->previousSlugSnapshot,
            $event->claimRoleSnapshot,
            $event->previousClaimRoleSnapshot,
            $event->relatedScope,
            $event->relatedEntity,
            $operationKey,
            $event->actorKey,
            $event->reason,
            $event->correlationKey,
            $event->occurredAt,
            $event->originalOccurredAt,
        )];
        $result = new SlugMutationResultDTO($fixture->operationType, $operationKey, false, $fixture->before, $fixture->after, $fixture->affectedClaims, $fixture->previousSlug, $fixture->currentSlug, $fixture->changeType, $fixture->revision, $history);
        $snapshot = ResultSnapshotEncoder::encode($result);
        $malformed = substr_replace($snapshot, ',"extra":true}', -1, 0);

        $this->expectException(SlugPersistenceInvariantException::class);
        ResultSnapshotStorage::decodeCommitted(
            new ResultSnapshotMetadata('mutation', 1, $result->operationType, $operationKey, $malformed),
            ContractFixtures::registry(),
        );
    }
}
