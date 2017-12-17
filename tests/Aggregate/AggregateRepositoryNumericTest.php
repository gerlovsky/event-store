<?php
/**
 * This file is part of the prooph/event-store.
 * (c) 2014-2017 prooph software GmbH <contact@prooph.de>
 * (c) 2015-2017 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ProophTest\EventStore\Aggregate;

use Prooph\Common\Event\ActionEvent;
use Prooph\Common\Event\ProophActionEventEmitter;
use Prooph\EventStore\Adapter\Adapter;
use Prooph\EventStore\Aggregate\AggregateRepository;
use Prooph\EventStore\Aggregate\AggregateType;
use Prooph\EventStore\Aggregate\ConfigurableAggregateTranslator;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\Snapshot\Adapter\InMemoryAdapter;
use Prooph\EventStore\Snapshot\Snapshot;
use Prooph\EventStore\Snapshot\SnapshotStore;
use Prooph\EventStore\Stream\Stream;
use Prooph\EventStore\Stream\StreamName;
use ProophTest\EventStore\Mock\UserCreated;
use ProophTest\EventStore\Mock\UsernameChanged;
use ProophTest\EventStore\Mock\UserNumericId;
use ProophTest\EventStore\TestCase;
use Prophecy\Argument;

class AggregateRepositoryNumericTest extends TestCase
{
    /**
     * @var AggregateRepository
     */
    private $repository;

    /**
     * @var SnapshotStore
     */
    private $snapshotStore;

    protected function setUp()
    {
        parent::setUp();

        $this->eventStore->getActionEventEmitter()->attachListener('commit.post', function (ActionEvent $event) use (&$recordedEvents) {
            foreach ($event->getParam('recordedEvents', new \ArrayIterator()) as $recordedEvent) {
                $this->assertTrue(is_string($recordedEvent->metadata()['aggregate_id']), 'Aggregate ID is not of type string');
            }
        });

        $this->repository = new AggregateRepository(
            $this->eventStore,
            AggregateType::fromAggregateRootClass('ProophTest\EventStore\Mock\UserNumericId'),
            new ConfigurableAggregateTranslator()
        );

        $this->eventStore->beginTransaction();

        $this->eventStore->create(new Stream(new StreamName('event_stream'), new \ArrayIterator()));

        $this->eventStore->commit();
    }


    /**
     * @test
     */
    public function it_adds_a_new_aggregate()
    {
        $this->eventStore->beginTransaction();

        $user = UserNumericId::create('100000', 'John Doe', 'contact@prooph.de');

        $this->repository->addAggregateRoot($user);

        $this->eventStore->commit();

        $fetchedUser = $this->repository->getAggregateRoot(
            $user->getId()
        );

        $this->assertInstanceOf('ProophTest\EventStore\Mock\UserNumericId', $fetchedUser);

        $this->assertNotSame($user, $fetchedUser);

        $this->assertEquals('John Doe', $fetchedUser->name());

        $this->assertEquals('contact@prooph.de', $fetchedUser->email());
    }

    /**
     * @test
     */
    public function it_tracks_changes_of_aggregate_but_always_returns_a_fresh_instance_on_load()
    {
        $this->eventStore->beginTransaction();

        $user = UserNumericId::create('100000', 'John Doe', 'contact@prooph.de');

        $this->repository->addAggregateRoot($user);

        $this->eventStore->commit();

        $this->eventStore->beginTransaction();

        $fetchedUser = $this->repository->getAggregateRoot(
            $user->getId()
        );

        $this->assertNotSame($user, $fetchedUser);

        $fetchedUser->changeName('Max Mustermann');

        $this->eventStore->commit();

        $fetchedUser2 = $this->repository->getAggregateRoot(
            $user->getId()
        );
        $this->assertNotSame($fetchedUser, $fetchedUser2);

        $this->assertEquals('Max Mustermann', $fetchedUser2->name());
    }

    /**
     * @test
     * Test for https://github.com/prooph/event-store/issues/99
     */
    public function it_does_not_interfere_with_other_aggregate_roots_in_pending_events_index()
    {
        $this->eventStore->beginTransaction();

        $user = UserNumericId::create('100000', 'John Doe', 'contact@prooph.de');

        $this->repository->addAggregateRoot($user);

        $user2 = UserNumericId::create('200000', 'Max Mustermann', 'some@mail.com');

        $this->repository->addAggregateRoot($user2);

        $this->eventStore->commit();

        $this->eventStore->beginTransaction();

        //Fetch users from repository to simulate a normal program flow
        $user = $this->repository->getAggregateRoot($user->getId());
        $user2 = $this->repository->getAggregateRoot($user2->getId());

        $user->changeName('Daniel Doe');
        $user2->changeName('Jens Mustermann');

        $this->eventStore->commit();

        $fetchedUser1 = $this->repository->getAggregateRoot(
            $user->getId()
        );

        $fetchedUser2 = $this->repository->getAggregateRoot(
            $user2->getId()
        );

        $this->assertEquals('Daniel Doe', $fetchedUser1->name());
        $this->assertEquals('Jens Mustermann', $fetchedUser2->name());
    }

    /**
     * @test
     * @expectedException Prooph\EventStore\Aggregate\Exception\AggregateTypeException
     * @expectedExceptionMessage Aggregate root must be an object but type of string given
     */
    public function it_asserts_correct_aggregate_type()
    {
        $this->repository->addAggregateRoot('invalid');
    }

    /**
     * @test
     */
    public function it_returns_early_on_get_aggregate_root_when_there_are_no_stream_events()
    {
        $this->assertNull($this->repository->getAggregateRoot('something'));
    }

    /**
     * @test
     */
    public function it_loads_the_entire_stream_if_one_stream_per_aggregate_is_enabled()
    {
        $adapter = $this->prophesize(Adapter::class);

        $adapter->load(Argument::that(function (StreamName $streamName) {
            return $streamName->toString() === UserNumericId::class . '-123';
        }), null)->willReturn(new Stream(new StreamName(UserNumericId::class . '-123'), new \ArrayIterator([])));

        $repository = new AggregateRepository(
            new EventStore($adapter->reveal(), new ProophActionEventEmitter()),
            AggregateType::fromAggregateRootClass(UserNumericId::class),
            new ConfigurableAggregateTranslator(),
            null,
            null,
            true
        );

        $repository->getAggregateRoot('123');
    }

    /**
     * @test
     */
    public function it_uses_snapshot_store()
    {
        $this->prepareSnapshotStoreAggregateRepository();

        $this->eventStore->beginTransaction();

        $user = UserNumericId::create('100000', 'John Doe', 'contact@prooph.de');

        $this->repository->addAggregateRoot($user);

        $this->eventStore->commit();

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $snapshot = new Snapshot(
            AggregateType::fromAggregateRootClass('ProophTest\EventStore\Mock\UserNumericId'),
            $user->getId(),
            $user,
            1,
            $now
        );

        // short getter assertion
        $this->assertSame($now, $snapshot->createdAt());

        $this->snapshotStore->save($snapshot);

        $loadedEvents = [];

        $this->eventStore->getActionEventEmitter()->attachListener(
            'loadEventsByMetadataFrom.post',
            function (ActionEvent $event) use (&$loadedEvents) {
                foreach ($event->getParam('streamEvents', []) as $streamEvent) {
                    $loadedEvents[] = $streamEvent;
                }
            }
        );

        $this->repository->getAggregateRoot(
            $user->getId()
        );

        $this->assertEmpty($loadedEvents);
    }

    /**
     * @test
     */
    public function it_uses_snapshot_store_while_snapshot_store_is_empty()
    {
        $this->prepareSnapshotStoreAggregateRepository();

        $this->eventStore->beginTransaction();

        $user = UserNumericId::create('100000', 'John Doe', 'contact@prooph.de');

        $this->repository->addAggregateRoot($user);

        $this->eventStore->commit();

        $loadedEvents = [];

        $this->eventStore->getActionEventEmitter()->attachListener(
            'loadEventsByMetadataFrom.post',
            function (ActionEvent $event) use (&$loadedEvents) {
                foreach ($event->getParam('streamEvents', []) as $streamEvent) {
                    $loadedEvents[] = $streamEvent;
                }
            }
        );

        $this->repository->getAggregateRoot(
            $user->getId()
        );

        $this->assertCount(1, $loadedEvents);
        $this->assertInstanceOf(UserCreated::class, $loadedEvents[0]);
    }

    /**
     * @test
     */
    public function it_uses_snapshot_store_and_applies_pending_events()
    {
        $this->prepareSnapshotStoreAggregateRepository();

        $this->eventStore->beginTransaction();

        $user = UserNumericId::create('100000', 'John Doe', 'contact@prooph.de');

        $this->repository->addAggregateRoot($user);

        $this->eventStore->commit();

        $snapshot = new Snapshot(
            AggregateType::fromAggregateRootClass('ProophTest\EventStore\Mock\UserNumericId'),
            $user->getId(),
            $user,
            1,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );

        $this->snapshotStore->save($snapshot);

        $this->eventStore->beginTransaction();

        $fetchedUser = $this->repository->getAggregateRoot(
            $user->getId()
        );

        $fetchedUser->changeName('Max Mustermann');

        $this->eventStore->commit();

        $loadedEvents = [];

        $this->eventStore->getActionEventEmitter()->attachListener(
            'loadEventsByMetadataFrom.post',
            function (ActionEvent $event) use (&$loadedEvents) {
                foreach ($event->getParam('streamEvents', []) as $streamEvent) {
                    $loadedEvents[] = $streamEvent;
                }
                $event->getParam('streamEvents')->rewind();
            }
        );

        $this->repository->getAggregateRoot(
            $user->getId()
        );

        $this->assertCount(1, $loadedEvents);
        $this->assertInstanceOf(UsernameChanged::class, $loadedEvents[0]);
        $this->assertEquals(2, $this->repository->extractAggregateVersion($fetchedUser));
    }

    protected function prepareSnapshotStoreAggregateRepository()
    {
        parent::setUp();

        $this->snapshotStore = new SnapshotStore(new InMemoryAdapter());

        $this->repository = new AggregateRepository(
            $this->eventStore,
            AggregateType::fromAggregateRootClass('ProophTest\EventStore\Mock\UserNumericId'),
            new ConfigurableAggregateTranslator(),
            $this->snapshotStore
        );

        $this->eventStore->beginTransaction();

        $this->eventStore->create(new Stream(new StreamName('event_stream'), new \ArrayIterator()));

        $this->eventStore->commit();
    }

    /**
     * @test
     * Test for https://github.com/prooph/event-store/issues/179
     */
    public function it_tracks_changes_of_aggregate_but_returns_a_same_instance_within_transaction()
    {
        $this->eventStore->beginTransaction();

        $user = UserNumericId::create('100000', 'John Doe', 'contact@prooph.de');

        $this->repository->addAggregateRoot($user);

        $this->eventStore->commit();

        $this->eventStore->beginTransaction();

        $fetchedUser1 = $this->repository->getAggregateRoot(
            $user->getId()
        );

        $fetchedUser2 = $this->repository->getAggregateRoot(
            $user->getId()
        );

        $this->assertSame($fetchedUser1, $fetchedUser2);

        $fetchedUser1->changeName('Max Mustermann');

        $this->assertSame($fetchedUser1, $fetchedUser2);

        $this->eventStore->commit();
    }
}
