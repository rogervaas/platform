<?php

namespace Oro\Bundle\SecurityBundle\Tests\Unit\Acl\Persistence;

use Doctrine\Common\Collections\ArrayCollection;
use Oro\Bundle\SecurityBundle\Acl\AccessLevel;
use Oro\Bundle\SecurityBundle\Acl\Domain\ObjectIdentityFactory;
use Oro\Bundle\SecurityBundle\Acl\Extension\EntityMaskBuilder;
use Oro\Bundle\SecurityBundle\Acl\Permission\MaskBuilder;
use Oro\Bundle\SecurityBundle\Acl\Persistence\AclManager;
use Oro\Bundle\SecurityBundle\Acl\Persistence\AclPrivilegeRepository;
use Oro\Bundle\SecurityBundle\Model\AclPermission;
use Oro\Bundle\SecurityBundle\Model\AclPrivilege;
use Oro\Bundle\SecurityBundle\Model\AclPrivilegeIdentity;
use Symfony\Component\Security\Acl\Domain\ObjectIdentity;
use Symfony\Component\Security\Acl\Exception\NotAllAclsFoundException;
use Symfony\Component\Security\Acl\Model\EntryInterface;

class AclPrivilegeRepositoryTest extends \PHPUnit_Framework_TestCase
{
    /** @var AclPrivilegeRepository */
    private $repository;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    private $manager;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    private $extensionSelector;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    private $extension;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    private $aceProvider;

    protected function setUp()
    {
        $this->extension = $this->getMock('Oro\Bundle\SecurityBundle\Acl\Extension\AclExtensionInterface');
        $this->extension->expects($this->any())
            ->method('getObjectIdentity')
            ->will(
                $this->returnCallback(
                    function ($object) {
                        return new ObjectIdentity(
                            substr($object, 0, strpos($object, ':')),
                            substr($object, strpos($object, ':') + 1)
                        );
                    }
                )
            );
        $this->extension->expects($this->any())
            ->method('getMaskBuilder')
            ->will($this->returnValue(new EntityMaskBuilder()));
        $this->extension->expects($this->any())
            ->method('getAllMaskBuilders')
            ->will($this->returnValue(array(new EntityMaskBuilder())));

        $this->extensionSelector = $this->getMockBuilder('Oro\Bundle\SecurityBundle\Acl\Extension\AclExtensionSelector')
            ->disableOriginalConstructor()
            ->getMock();
        $this->extensionSelector->expects($this->any())
            ->method('select')
            ->will($this->returnValue($this->extension));

        $this->aceProvider = $this->getMockBuilder('Oro\Bundle\SecurityBundle\Acl\Persistence\AceManipulationHelper')
            ->disableOriginalConstructor()
            ->getMock();

        $this->manager = $this->getMockBuilder('Oro\Bundle\SecurityBundle\Acl\Persistence\AclManager')
            ->disableOriginalConstructor()
            ->getMock();
        $this->manager->expects($this->any())
            ->method('getExtensionSelector')
            ->will($this->returnValue($this->extensionSelector));
        $this->manager->expects($this->any())
            ->method('getAllExtensions')
            ->will($this->returnValue(array($this->extension)));
        $this->manager->expects($this->any())
            ->method('getAceProvider')
            ->will($this->returnValue($this->aceProvider));

        $this->repository = new AclPrivilegeRepository($this->manager);
    }

    public function testGetPermissionNames()
    {
        $extensionKey = 'test';
        $permissions = array('VIEW', 'EDIT');

        $this->manager->expects($this->once())
            ->method('getRootOid')
            ->with($this->equalTo($extensionKey))
            ->will($this->returnValue(new ObjectIdentity($extensionKey, ObjectIdentityFactory::ROOT_IDENTITY_TYPE)));
        $this->extension->expects($this->once())
            ->method('getPermissions')
            ->will($this->returnValue($permissions));

        $this->assertEquals(
            $permissions,
            $this->repository->getPermissionNames($extensionKey)
        );
    }

    public function testGetPermissionNamesForSeveralAclExtensions()
    {
        $extensionKey1 = 'test1';
        $permissions1 = array('VIEW', 'EDIT');

        $extensionKey2 = 'test2';
        $permissions2 = array('VIEW', 'CREATE');

        $this->manager->expects($this->exactly(2))
            ->method('getRootOid')
            ->will(
                $this->returnValueMap(
                    array(
                        array(
                            $extensionKey1,
                            new ObjectIdentity($extensionKey1, ObjectIdentityFactory::ROOT_IDENTITY_TYPE)
                        ),
                        array(
                            $extensionKey2,
                            new ObjectIdentity($extensionKey2, ObjectIdentityFactory::ROOT_IDENTITY_TYPE)
                        ),
                    )
                )
            );
        $this->extension->expects($this->at(0))
            ->method('getPermissions')
            ->will($this->returnValue($permissions1));
        $this->extension->expects($this->at(1))
            ->method('getPermissions')
            ->will($this->returnValue($permissions2));

        $this->assertEquals(
            array('VIEW', 'EDIT', 'CREATE'),
            $this->repository->getPermissionNames(array($extensionKey1, $extensionKey2))
        );
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testGetPrivileges()
    {
        $extensionKey = 'test';
        $classes = array(
            'Acme\Class1',
            'Acme\Class2',
        );

        $rootOid = new ObjectIdentity($extensionKey, ObjectIdentityFactory::ROOT_IDENTITY_TYPE);
        $rootAcl = $this->getMock('Symfony\Component\Security\Acl\Model\AclInterface');

        $oid1 = new ObjectIdentity($extensionKey, $classes[0]);
        $oid1Acl = $this->getMock('Symfony\Component\Security\Acl\Model\AclInterface');
        $oid2 = new ObjectIdentity($extensionKey, $classes[1]);

        $oidsWithRoot = array($rootOid, $oid1, $oid2);

        $aclsSrc = array(
            array('oid' => $rootOid, 'acl' => $rootAcl),
            array('oid' => $oid1, 'acl' => $oid1Acl),
            array('oid' => $oid2, 'acl' => null),
        );

        $allowedPermissions = array();
        $allowedPermissions[(string)$rootOid] = array('VIEW', 'CREATE', 'EDIT');
        $allowedPermissions[(string)$oid1] = array('VIEW', 'CREATE', 'EDIT');
        $allowedPermissions[(string)$oid2] = array('VIEW', 'CREATE');

        $rootAce = $this->getAce('root');
        $rootAcl->expects($this->any())
            ->method('getObjectAces')
            ->will($this->returnValue(array($rootAce)));
        $rootAcl->expects($this->never())
            ->method('getClassAces');

        $oid1Ace = $this->getAce('oid1');
        $oid1Acl->expects($this->any())
            ->method('getClassAces')
            ->will($this->returnValue(array($oid1Ace)));
        $oid1Acl->expects($this->once())
            ->method('getObjectAces')
            ->will($this->returnValue(array()));

        $sid = $this->getMock('Symfony\Component\Security\Acl\Model\SecurityIdentityInterface');

        $this->extension->expects($this->once())
            ->method('getExtensionKey')
            ->will($this->returnValue($extensionKey));
        $this->extension->expects($this->once())
            ->method('getClasses')
            ->will($this->returnValue($classes));
        $this->extension->expects($this->any())
            ->method('getAllowedPermissions')
            ->will(
                $this->returnCallback(
                    function ($oid) use (&$allowedPermissions) {
                        return $allowedPermissions[(string)$oid];
                    }
                )
            );
        $this->extension->expects($this->any())
            ->method('adaptRootMask')
            ->will(
                $this->returnCallback(
                    function ($mask, $object) {
                        if ($mask === 'root' && $object === 'test:Acme\Class2') {
                            return 'adaptedRoot';
                        }

                        return $mask;
                    }
                )
            );
        $this->extension->expects($this->any())
            ->method('getPermissions')
            ->will($this->returnValue(array('VIEW', 'CREATE', 'EDIT')));
        $this->extension->expects($this->any())
            ->method('getAccessLevel')
            ->will(
                $this->returnCallback(
                    function ($mask, $permission) {
                        switch ($permission) {
                            case 'VIEW':
                                if ($mask === 'root') {
                                    return AccessLevel::GLOBAL_LEVEL;
                                } elseif ($mask === 'oid1') {
                                    return AccessLevel::BASIC_LEVEL;
                                }
                                break;
                            case 'CREATE':
                                if ($mask === 'root') {
                                    return AccessLevel::DEEP_LEVEL;
                                } elseif ($mask === 'oid1') {
                                    return AccessLevel::BASIC_LEVEL;
                                }
                                break;
                            case 'EDIT':
                                if ($mask === 'root') {
                                    return AccessLevel::LOCAL_LEVEL;
                                } elseif ($mask === 'oid1') {
                                    return AccessLevel::NONE_LEVEL;
                                }
                                break;
                        }
                        if ($mask === 'adaptedRoot') {
                            return AccessLevel::SYSTEM_LEVEL;
                        }

                        return AccessLevel::NONE_LEVEL;
                    }
                )
            );

        $this->manager->expects($this->once())
            ->method('getRootOid')
            ->with($this->equalTo($extensionKey))
            ->will($this->returnValue($rootOid));

        $this->manager->expects($this->once())
            ->method('findAcls')
            ->with($this->identicalTo($sid), $this->equalTo($oidsWithRoot))
            ->will(
                $this->returnCallback(
                    function () use (&$aclsSrc) {
                        return self::getAcls($aclsSrc);
                    }
                )
            );

        $this->aceProvider->expects($this->any())
            ->method('getAces')
            ->will(
                $this->returnCallback(
                    function ($acl, $type, $field) use (&$rootAcl, &$oid1Acl) {
                        if ($acl === $oid1Acl) {
                            $a = $oid1Acl;
                        } else {
                            $a = $rootAcl;
                        }

                        return $a->{"get{$type}Aces"}();
                    }
                )
            );

        $result = $this->repository->getPrivileges($sid);

        $this->assertCount(count($classes) + 1, $result);
        $this->assertEquals('test:(root)', $result[0]->getIdentity()->getId());
        $this->assertEquals('test:Acme\Class2', $result[1]->getIdentity()->getId());
        $this->assertEquals('test:Acme\Class1', $result[2]->getIdentity()->getId());

        $this->assertEquals(3, $result[0]->getPermissionCount());
        $this->assertEquals(2, $result[1]->getPermissionCount());
        $this->assertEquals(3, $result[2]->getPermissionCount());

        $p = $result[0]->getPermissions();
        $this->assertEquals(AccessLevel::GLOBAL_LEVEL, $p['VIEW']->getAccessLevel());
        $this->assertEquals(AccessLevel::DEEP_LEVEL, $p['CREATE']->getAccessLevel());
        $this->assertEquals(AccessLevel::LOCAL_LEVEL, $p['EDIT']->getAccessLevel());

        $p = $result[1]->getPermissions();
        $this->assertEquals(AccessLevel::SYSTEM_LEVEL, $p['VIEW']->getAccessLevel());
        $this->assertEquals(AccessLevel::SYSTEM_LEVEL, $p['CREATE']->getAccessLevel());
        $this->assertFalse($p->containsKey('EDIT'));

        $p = $result[2]->getPermissions();
        $this->assertEquals(AccessLevel::BASIC_LEVEL, $p['VIEW']->getAccessLevel());
        $this->assertEquals(AccessLevel::BASIC_LEVEL, $p['CREATE']->getAccessLevel());
        $this->assertEquals(AccessLevel::NONE_LEVEL, $p['EDIT']->getAccessLevel());
    }

    private function initSavePrivileges($extensionKey, $rootOid)
    {
        $this->extension->expects($this->any())
            ->method('getExtensionKey')
            ->will($this->returnValue($extensionKey));
        $this->extension->expects($this->any())
            ->method('getPermissions')
            ->will($this->returnValue(array('VIEW', 'CREATE', 'EDIT')));
        $this->extension->expects($this->any())
            ->method('adaptRootMask')
            ->will(
                $this->returnCallback(
                    function ($mask, $object) {
                        return $mask;
                    }
                )
            );

        $this->manager->expects($this->any())
            ->method('getRootOid')
            ->with($this->equalTo($extensionKey))
            ->will($this->returnValue($rootOid));

        $this->manager->expects($this->once())
            ->method('flush');
    }

    private $expectationsForSetPermission;
    private $triggeredExpectationsForSetPermission;

    private function validateExpectationsForSetPermission()
    {
        foreach ($this->expectationsForSetPermission as $expectedOid => $expectedMasks) {
            if (!isset($this->triggeredExpectationsForSetPermission[$expectedOid])) {
                throw new \RuntimeException(sprintf('Expected call of "setPermission" for %s.', $expectedOid));
            }
        }
    }

    private function setExpectationsForSetPermission($sid, array $expectations)
    {
        $this->expectationsForSetPermission = $expectations;
        $this->triggeredExpectationsForSetPermission = array();
        $triggeredExpectationsForSetPermission = & $this->triggeredExpectationsForSetPermission;
        $this->manager->expects($this->any())
            ->method('setPermission')
            ->with($this->identicalTo($sid))
            ->will(
                $this->returnCallback(
                    function ($sid, $oid, $mask) use (&$expectations, &$triggeredExpectationsForSetPermission) {
                        /** @var ObjectIdentity $oid */
                        $expectedMask = null;

                        foreach ($expectations as $expectedOid => $expectedMasks) {
                            if ($expectedOid === $oid->getIdentifier() . ':' . $oid->getType()) {
                                $expectedMask = self::getMask($expectedMasks);
                                $triggeredExpectationsForSetPermission[$expectedOid] =
                                    isset($triggeredExpectationsForSetPermission[$expectedOid])
                                        ? $triggeredExpectationsForSetPermission[$expectedOid] + 1
                                        : 0;
                                break;
                            }
                        }

                        if ($expectedMask !== null) {
                            if ($expectedMask !== $mask) {
                                throw new \RuntimeException(
                                    sprintf(
                                        'Call "setPermission" with invalid mask for %s. Expected: %s. Actual: %s.',
                                        $oid,
                                        EntityMaskBuilder::getPatternFor($expectedMask),
                                        EntityMaskBuilder::getPatternFor($mask)
                                    )
                                );
                            }
                        } else {
                            throw new \RuntimeException(sprintf('Unexpected call of "setPermission" for %s.', $oid));
                        }
                    }
                )
            );
    }

    private $expectationsForDeletePermission;
    private $triggeredExpectationsForDeletePermission;

    private function validateExpectationsForDeletePermission()
    {
        foreach ($this->expectationsForDeletePermission as $expectedOid => $expectedMasks) {
            if (!isset($this->triggeredExpectationsForDeletePermission[$expectedOid])) {
                throw new \RuntimeException(sprintf('Expected call of "deletePermission" for %s.', $expectedOid));
            }
        }
    }

    private function setExpectationsForDeletePermission($sid, array $expectations)
    {
        $this->expectationsForDeletePermission = $expectations;
        $this->triggeredExpectationsForDeletePermission = array();
        $triggeredExpectationsForDeletePermission = & $this->triggeredExpectationsForDeletePermission;
        $this->manager->expects($this->any())
            ->method('deletePermission')
            ->with($this->identicalTo($sid))
            ->will(
                $this->returnCallback(
                    function ($sid, $oid, $mask) use (&$expectations, &$triggeredExpectationsForDeletePermission) {
                        /** @var ObjectIdentity $oid */
                        $expectedMask = null;

                        foreach ($expectations as $expectedOid => $expectedMasks) {
                            if ($expectedOid === $oid->getIdentifier() . ':' . $oid->getType()) {
                                $expectedMask = self::getMask($expectedMasks);
                                $triggeredExpectationsForDeletePermission[$expectedOid] =
                                    isset($triggeredExpectationsForDeletePermission[$expectedOid])
                                        ? $triggeredExpectationsForDeletePermission[$expectedOid] + 1
                                        : 0;
                                break;
                            }
                        }

                        if ($expectedMask !== null) {
                            if ($expectedMask !== $mask) {
                                throw new \RuntimeException(
                                    sprintf(
                                        'Call "deletePermission" with invalid mask for %s. Expected: %s. Actual: %s.',
                                        $oid,
                                        EntityMaskBuilder::getPatternFor($expectedMask),
                                        EntityMaskBuilder::getPatternFor($mask)
                                    )
                                );
                            }
                        } else {
                            throw new \RuntimeException(sprintf('Unexpected call of "deletePermission" for %s.', $oid));
                        }
                    }
                )
            );
    }

    private $expectationsForGetAces;
    private $triggeredExpectationsForGetAces;

    private function validateExpectationsForGetAces()
    {
        foreach ($this->expectationsForGetAces as $expectedOid => $expectedMasks) {
            if (!isset($this->triggeredExpectationsForGetAces[$expectedOid])) {
                throw new \RuntimeException(sprintf('Expected call of "getAces" for %s.', $expectedOid));
            }
        }
    }

    private function setExpectationsForGetAces(array $expectations)
    {
        $this->expectationsForGetAces = $expectations;
        $this->triggeredExpectationsForGetAces = array();
        $triggeredExpectationsForGetAces = & $this->triggeredExpectationsForGetAces;
        $this->manager->expects($this->any())
            ->method('getAces')
            ->will(
                $this->returnCallback(
                    function ($sid, $oid) use (&$expectations, &$triggeredExpectationsForGetAces) {
                        /** @var ObjectIdentity $oid */
                        foreach ($expectations as $expectedOid => $expectedAces) {
                            if ($expectedOid === $oid->getIdentifier() . ':' . $oid->getType()) {
                                $triggeredExpectationsForGetAces[$expectedOid] =
                                    isset($triggeredExpectationsForGetAces[$expectedOid])
                                        ? $triggeredExpectationsForGetAces[$expectedOid] + 1
                                        : 0;

                                return $expectedAces;
                            }
                        }

                        return array();
                    }
                )
            );
    }

    public function testSavePrivilegesForNewRoleWithoutRoot()
    {
        $extensionKey = 'test';
        $rootOid = new ObjectIdentity($extensionKey, ObjectIdentityFactory::ROOT_IDENTITY_TYPE);

        $privileges = new ArrayCollection();
        $privileges[] = self::getPrivilege(
            'test:Acme\Class1',
            array(
                'VIEW' => AccessLevel::SYSTEM_LEVEL,
                'CREATE' => AccessLevel::BASIC_LEVEL,
                'EDIT' => AccessLevel::NONE_LEVEL,
            )
        );

        $sid = $this->getMock('Symfony\Component\Security\Acl\Model\SecurityIdentityInterface');
        $this->initSavePrivileges($extensionKey, $rootOid);

        $this->setExpectationsForGetAces(array());

        $this->setExpectationsForSetPermission(
            $sid,
            array(
                'test:(root)' => array(),
                'test:Acme\Class1' => array('VIEW_SYSTEM', 'CREATE_BASIC'),
            )
        );

        $this->repository->savePrivileges($sid, $privileges);

        $this->validateExpectationsForGetAces();
        $this->validateExpectationsForSetPermission();
    }

    public function testSavePrivilegesForNewRoleWithRoot()
    {
        $extensionKey = 'test';
        $rootOid = new ObjectIdentity($extensionKey, ObjectIdentityFactory::ROOT_IDENTITY_TYPE);

        $privileges = new ArrayCollection();
        $privileges[] = self::getPrivilege(
            'test:(root)',
            array(
                'VIEW' => AccessLevel::SYSTEM_LEVEL,
                'CREATE' => AccessLevel::BASIC_LEVEL,
                'EDIT' => AccessLevel::NONE_LEVEL,
            )
        );
        $privileges[] = self::getPrivilege(
            'test:Acme\Class1',
            array(
                'VIEW' => AccessLevel::SYSTEM_LEVEL,
                'CREATE' => AccessLevel::BASIC_LEVEL,
                'EDIT' => AccessLevel::NONE_LEVEL,
            )
        );
        $privileges[] = self::getPrivilege(
            'test:Acme\Class2',
            array(
                'VIEW' => AccessLevel::SYSTEM_LEVEL,
                'CREATE' => AccessLevel::SYSTEM_LEVEL,
                'EDIT' => AccessLevel::NONE_LEVEL,
            )
        );

        $sid = $this->getMock('Symfony\Component\Security\Acl\Model\SecurityIdentityInterface');
        $this->initSavePrivileges($extensionKey, $rootOid);

        $this->setExpectationsForGetAces(array());

        $this->setExpectationsForSetPermission(
            $sid,
            array(
                'test:(root)' => array('VIEW_SYSTEM', 'CREATE_BASIC'),
                'test:Acme\Class2' => array('VIEW_SYSTEM', 'CREATE_SYSTEM'),
            )
        );

        $this->repository->savePrivileges($sid, $privileges);

        $this->validateExpectationsForGetAces();
        $this->validateExpectationsForSetPermission();
    }

    public function testSavePrivilegesForExistingRole()
    {
        $extensionKey = 'test';
        $rootOid = new ObjectIdentity($extensionKey, ObjectIdentityFactory::ROOT_IDENTITY_TYPE);

        $class3Ace = $this->getAce(self::getMask(array('VIEW_BASIC', 'CREATE_BASIC')));

        $privileges = new ArrayCollection();
        $privileges[] = self::getPrivilege(
            'test:(root)',
            array(
                'VIEW' => AccessLevel::SYSTEM_LEVEL,
                'CREATE' => AccessLevel::BASIC_LEVEL,
                'EDIT' => AccessLevel::NONE_LEVEL,
            )
        );
        $privileges[] = self::getPrivilege(
            'test:Acme\Class1', // no changes because permissions = root
            array(
                'VIEW' => AccessLevel::SYSTEM_LEVEL,
                'CREATE' => AccessLevel::BASIC_LEVEL,
                'EDIT' => AccessLevel::NONE_LEVEL,
            )
        );
        $privileges[] = self::getPrivilege(
            'test:Acme\Class2', // new
            array(
                'VIEW' => AccessLevel::SYSTEM_LEVEL,
                'CREATE' => AccessLevel::SYSTEM_LEVEL,
                'EDIT' => AccessLevel::NONE_LEVEL,
            )
        );
        $privileges[] = self::getPrivilege(
            'test:Acme\Class3', // existing and should be deleted because permissions = root
            array(
                'VIEW' => AccessLevel::SYSTEM_LEVEL,
                'CREATE' => AccessLevel::BASIC_LEVEL,
                'EDIT' => AccessLevel::NONE_LEVEL,
            )
        );

        $sid = $this->getMock('Symfony\Component\Security\Acl\Model\SecurityIdentityInterface');
        $this->initSavePrivileges($extensionKey, $rootOid);

        $this->setExpectationsForGetAces(
            array(
                'test:Acme\Class3' => array($class3Ace)
            )
        );

        $this->setExpectationsForSetPermission(
            $sid,
            array(
                'test:(root)' => array('VIEW_SYSTEM', 'CREATE_BASIC'),
                'test:Acme\Class2' => array('VIEW_SYSTEM', 'CREATE_SYSTEM'),
            )
        );
        $this->setExpectationsForDeletePermission(
            $sid,
            array(
                'test:Acme\Class3' => array('VIEW_BASIC', 'CREATE_BASIC'),
            )
        );

        $this->repository->savePrivileges($sid, $privileges);

        $this->validateExpectationsForGetAces();
        $this->validateExpectationsForSetPermission();
        $this->validateExpectationsForDeletePermission();
    }

    private static function getMask(array $masks, MaskBuilder $maskBuilder = null)
    {
        if ($maskBuilder === null) {
            $maskBuilder = new EntityMaskBuilder();
        }
        $maskBuilder->reset();
        foreach ($masks as $mask) {
            $maskBuilder->add($mask);
        }

        return $maskBuilder->get();
    }

    /**
     * @param string $id
     * @param array $permissions
     * @return AclPrivilege
     */
    private static function getPrivilege($id, array $permissions)
    {
        $privilege = new AclPrivilege();
        $privilege->setIdentity(new AclPrivilegeIdentity($id));
        foreach ($permissions as $name => $accessLevel) {
            $privilege->addPermission(new AclPermission($name, $accessLevel));
        }

        return $privilege;
    }

    private function getAce($mask)
    {
        $ace = $this->getMock('Symfony\Component\Security\Acl\Model\EntryInterface');
        $ace->expects($this->any())->method('isGranting')->will($this->returnValue(true));
        $ace->expects($this->any())->method('getMask')->will($this->returnValue($mask));

        return $ace;
    }

    /**
     * @param array $src
     * @return \SplObjectStorage
     * @throws NotAllAclsFoundException
     */
    private static function getAcls(array $src)
    {
        $isPartial = false;
        $acls = new \SplObjectStorage();
        foreach ($src as $item) {
            if ($item['acl'] !== null) {
                $acls->attach($item['oid'], $item['acl']);
            } else {
                $isPartial = true;
            }
        }

        if ($isPartial) {
            $ex = new NotAllAclsFoundException();
            $ex->setPartialResult($acls);
            throw $ex;
        }

        return $acls;
    }
}
