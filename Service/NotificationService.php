<?php

namespace Modera\NotificationBundle\Service;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Modera\NotificationBundle\Transport\UID;
use Modera\NotificationBundle\Entity\NotificationDefinition;
use Modera\NotificationBundle\Entity\UserNotificationInstance;
use Modera\NotificationBundle\Model\NotificationInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @deprecated This service is deprecated in favor to Dispatching/NotificationCenter, use it instead.
 *
 * Service provides basic routines for manipulating notifications - dispatching(creating), querying, batch changing
 * of notification statuses.
 *
 * @author    Sergei Lissovski <sergei.lissovski@modera.org>
 * @copyright 2015 Modera Foundation
 */
class NotificationService
{
    /**
     * @var Registry
     */
    protected $doctrineRegistry;

    /**
     * @param Registry $registry
     */
    public function __construct(RegistryInterface $doctrineRegistry)
    {
        $this->doctrineRegistry = $doctrineRegistry;
    }

    /**
     * @deprecated Use NotificationCenter::createNotificationBuilder() method and then its dispatch() method.
     *
     * Dispatches a notification.
     *
     * @param string          $group
     * @param string          $message
     * @param UserInterface[] $recipients
     * @param array           $meta
     *
     * @return mixed A notification ID
     */
    public function dispatch($group, $message, array $recipients, array $meta = array())
    {
        $def = new NotificationDefinition($message, $group);
        $def->setMeta($meta);

        foreach ($recipients as $user) {
            $def->createInstance($user);
        }

        /* @var EntityManager $em */
        $em = $this->doctrineRegistry->getManager();

        $em->persist($def);
        $em->flush();

        return $def->getId();
    }

    /**
     * Finds all notifications by given $group/$user and changes their status to $newStatus.
     *
     * Possible array query keys are: recipient (instance of UserInterface), group (string), id(int, id of implementation
     * of NotificationInterface). By combining different keys you are able to change scope of notifications whose
     * statuses are going to be updated.
     *
     * @param int   $newStatus
     * @param array $arrayQuery
     */
    public function changeStatus($newStatus, array $arrayQuery)
    {
        /* @var EntityManager $em */
        $em = $this->doctrineRegistry->getManager();

        $querySegments = [
            sprintf('SELECT inc.id, inc.readAt FROM %s inc LEFT JOIN inc.definition def', UserNotificationInstance::clazz()),
        ];

        $hasId = isset($arrayQuery['id']);
        $hasRecipient = isset($arrayQuery['recipient']);
        $hasRecipients = isset($arrayQuery['recipients']) && is_array($arrayQuery['recipients']) && count($arrayQuery['recipients']) > 0;
        $hasGroup = isset($arrayQuery['group']);

        $filters = [];
        $queryParams = [];

        $querySegments[] = 'WHERE ';
        $filters[] = 'inc.status != ?'.count($queryParams);
        $queryParams[] = $newStatus;

        if ($hasId) {
            $filters[] = 'inc.id = ?'.count($queryParams); // related to MPFE-942
            $queryParams[] = $arrayQuery['id'];
        }
        if ($hasRecipient) {
            $filters[] = 'inc.recipient = ?'.count($queryParams);
            $queryParams[] = $arrayQuery['recipient'];
        }
        if ($hasRecipients) {
            $filters[] = sprintf('inc.recipient IN (?%d)', count($queryParams));
            $queryParams[] = $arrayQuery['recipients'];
        }
        if ($hasGroup) {
            $filters[] = 'def.groupName = ?'.count($queryParams);
            $queryParams[] = $arrayQuery['group'];
        }

        $query = implode(' ', $querySegments).implode(' AND ', $filters);
        $query = $em->createQuery($query);
        $query->setParameters($queryParams);

        foreach ($query->getResult($query::HYDRATE_ARRAY) as $instance) {
            $set = [
                'inc.status = :status',
                'inc.updatedAt = CURRENT_TIMESTAMP()',
            ];
            if (!$instance['readAt'] && $newStatus == UserNotificationInstance::STATUS_READ) {
                $set[] = 'inc.readAt = CURRENT_TIMESTAMP()';
            }

            $query = $em->createQuery(
                sprintf(
                    'UPDATE %s inc SET ' . join(', ', $set) . ' WHERE inc.id = :id',
                    UserNotificationInstance::clazz()
                )
            );
            $query->setParameter('status', $newStatus);
            $query->setParameter('id', $instance['id']);
            $query->execute();
        }
    }

    /**
     * Allows to fetch notifications from storage.
     *
     * Sample query.
     *
     * array(
     *     'group' => 'foo_group',
     *     'recipients' => [$user1, $user2] // instances of UserInterface,
     *     'status' => NotificationInterface::STATUS_NOT_READ
     * );
     *
     * If none of parameters is provided then all available notifications will be fetched.
     *
     * @param array $arrayQuery
     *
     * @return NotificationInterface[]
     */
    public function fetchBy(array $arrayQuery)
    {
        /* @var EntityManager $em */
        $em = $this->doctrineRegistry->getManager();
        $queryParams = [];

        $hasGroup = isset($arrayQuery['group']);
        $hasRecipients = isset($arrayQuery['recipients']) && is_array($arrayQuery['recipients']) && count($arrayQuery['recipients']) > 0;
        $hasStatus = isset($arrayQuery['status']);

        $whereSegments = [];
        if ($hasGroup || $hasRecipients || $hasStatus) {
            $querySegments[] = 'WHERE';

            if ($hasGroup) {
                $whereSegments[] = 'def.groupName = ?'.count($queryParams);
                $queryParams[] = $arrayQuery['group'];
            }
            if ($hasRecipients) {
                $whereSegments[] = sprintf('inc.recipient IN (?%d)', count($queryParams));
                $queryParams[] = $arrayQuery['recipients'];
            }
            if ($hasStatus) {
                $whereSegments[] = 'inc.status = ?'.count($queryParams);
                $queryParams[] = $arrayQuery['status'];
            }
        }

        $query = implode(' ', [
            sprintf('SELECT inc FROM %s inc LEFT JOIN inc.definition def', UserNotificationInstance::clazz()),
            count($whereSegments) > 0 ? 'WHERE' : '',
            implode(' AND ', $whereSegments),
            'ORDER BY inc.id',
        ]);

        $query = $em->createQuery($query);
        $query->setParameters($queryParams);

        return $query->getResult();
    }

    /**
     * Available query keys: ID (int), recipient (UserInterface). Here we mean "id" of implementation
     * of NotificationInterface, which represents a notification linked to a specific user.
     *
     * @throws \RuntimeException If more than one result is returned from persistence storage.
     *
     * @param array $arrayQuery
     *
     * @return NotificationInterface|null NULL is returned when no notification is found
     */
    public function fetchOneBy(array $arrayQuery)
    {
        /* @var EntityManager $em */
        $em = $this->doctrineRegistry->getManager();

        $hasId = isset($arrayQuery['id']);
        $hasRecipient = isset($arrayQuery['recipient']);

        // we fetch "definition" here to avoid issuing a separate query later if API user wants to
        // read notification's content (which happens quite often)
        $querySegments = [
            sprintf('SELECT inc FROM %s inc LEFT JOIN inc.definition def', UserNotificationInstance::clazz()),
        ];
        $queryParams = [];

        if ($hasId || $hasRecipient) {
            $querySegments[] = 'WHERE';
        }

        if ($hasId) {
            $querySegments[] = 'inc.id = ?'.count($queryParams); // used to be "def.id =", but caused a problem - MPFE-942
            $queryParams[] = $arrayQuery['id'];
        }
        if ($hasRecipient) {
            if ($hasId) {
                $querySegments[] = 'AND';
            }

            $querySegments[] = sprintf('inc.recipient IN (?%d)', count($queryParams));
            $queryParams[] = [$arrayQuery['recipient']];
        }

        $query = $em->createQuery(implode(' ', $querySegments));
        $query->setParameters($queryParams);

        $result = $query->getResult();
        if (count($result) > 1) {
            throw new \RuntimeException('More than one notification returned for query: '.json_encode($arrayQuery));
        } elseif (count($result) == 0) {
            return;
        }

        return $result[0];
    }

    /**
     * @param UID $uid
     * @param UserInterface $user
     *
     * @return NotificationInterface|null
     */
    public function fetchOneByUIDAndRecipient(UID $uid, UserInterface $user)
    {
        if ($uid->isGeneralized()) {
            throw new \InvalidArgumentException("Non-generalized UID is expected.");
        }

        $repository = $this->doctrineRegistry->getRepository(UserNotificationInstance::clazz());

        if ($uid->isUserSpecific()) {
            return $repository->find($uid->getNotification());
        } else {
            return $repository->findOneBy(array(
                'recipient' => $user,
                'definition' => $uid->getNotification(),
            ));
        }
    }

    /**
     * Saves/updates notification.
     *
     * @param NotificationInterface $notification
     */
    public function save(NotificationInterface $notification)
    {
        /* @var EntityManager $em */
        $em = $this->doctrineRegistry->getManager();

        $em->persist($notification);
        $em->flush();
    }
}
