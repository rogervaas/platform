<?php

namespace Oro\Bundle\EntityMergeBundle\Tests\Unit\DataGrid\Extension\MassAction;

use Oro\Bundle\DataGridBundle\Extension\Action\ActionConfiguration;
use Oro\Bundle\EntityConfigBundle\Config\Config as EntityConfig;
use Oro\Bundle\EntityMergeBundle\DataGrid\Extension\MassAction\MergeMassAction;

class MergeMassActionTest extends \PHPUnit_Framework_TestCase
{
    const MAX_ENTITIES_COUNT = 1;

    /**
     * @var MergeMassAction $target
     */
    private $target;

    protected function setUp()
    {
        $entityConfigProvider = $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider')
            ->disableOriginalConstructor()
            ->getMock();
        $entityConfig = new EntityConfig(
            $this->getMock('Oro\Bundle\EntityConfigBundle\Config\Id\ConfigIdInterface'),
            ['max_element_count' => self::MAX_ENTITIES_COUNT]
        );
        $entityConfigProvider->expects($this->any())
            ->method('getConfig')
            ->with('SomeEntityClass')
            ->willReturn($entityConfig);

        $translator = $this->getMock('Symfony\Component\Translation\TranslatorInterface');

        $this->target = new MergeMassAction($entityConfigProvider, $translator);
    }

    /**
     * @dataProvider getOptionsDataProvider
     */
    public function testGetOptions(array $actualOptions, array $expectedOptions)
    {
        $this->target->setOptions(ActionConfiguration::create($actualOptions));
        $this->assertEquals($expectedOptions, $this->target->getOptions()->toArray());
    }

    public function getOptionsDataProvider()
    {
        return array(
            'default_values'  => array(
                'actual'   => array(
                    'entity_name' => 'SomeEntityClass'
                ),
                'expected' => array(
                    'entity_name'       => 'SomeEntityClass',
                    'frontend_handle'   => 'redirect',
                    'handler'           => 'oro_entity_merge.mass_action.data_handler',
                    'frontend_type'     => 'merge-mass',
                    'route'             => 'oro_entity_merge_massaction',
                    'data_identifier'   => 'id',
                    'max_element_count' => self::MAX_ENTITIES_COUNT,
                    'label'             => null,
                    'route_parameters'  => array(),
                    'launcherOptions'   => array('iconClassName' => 'icon-random')
                )
            ),
            'override_values' => array(
                'actual'   => array(
                    'entity_name'       => 'SomeEntityClass',
                    'frontend_handle'   => 'custom_handler',
                    'handler'           => 'oro_entity_merge.mass_action.data_handler',
                    'frontend_type'     => 'custom-merge-mass',
                    'data_identifier'   => 'code',
                    'icon'              => 'custom',
                    'max_element_count' => self::MAX_ENTITIES_COUNT,
                    'route'             => 'oro_entity_merge_massaction',
                    'route_parameters'  => array()
                ),
                'expected' => array(
                    'entity_name'       => 'SomeEntityClass',
                    'frontend_handle'   => 'custom_handler',
                    'handler'           => 'oro_entity_merge.mass_action.data_handler',
                    'frontend_type'     => 'custom-merge-mass',
                    'data_identifier'   => 'code',
                    'launcherOptions'   => array('iconClassName' => 'icon-custom'),
                    'max_element_count' => self::MAX_ENTITIES_COUNT,
                    'route'             => 'oro_entity_merge_massaction',
                    'route_parameters'  => array(),
                    'label'             => null,
                )
            )
        );
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Trying to get name of unnamed object
     */
    public function testMergeMassActionSetOptionShouldThrowExceptionIfClassNameOptionIsEmpty()
    {
        $this->target->setOptions(ActionConfiguration::create(array()));
    }
}
