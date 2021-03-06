<?php

namespace SilverStripe\Versioned\GraphQL\Plugins;

use SilverStripe\Core\Extensible;
use SilverStripe\GraphQL\Schema\DataObject\Plugin\Paginator;
use SilverStripe\GraphQL\Schema\Exception\SchemaBuilderException;
use SilverStripe\GraphQL\Schema\Field\Field;
use SilverStripe\GraphQL\Schema\Interfaces\ModelTypePlugin;
use SilverStripe\GraphQL\Schema\Interfaces\SchemaUpdater;
use SilverStripe\GraphQL\Schema\Plugin\SortPlugin;
use SilverStripe\GraphQL\Schema\Schema;
use SilverStripe\GraphQL\Schema\Type\ModelType;
use SilverStripe\GraphQL\Schema\Type\Type;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Sortable;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\GraphQL\Resolvers\VersionedResolver;
use SilverStripe\Versioned\Versioned;
use Closure;

// GraphQL dependency is optional in versioned,
// and the following implementation relies on existence of this class (in GraphQL v4)
if (!interface_exists(ModelTypePlugin::class)) {
    return;
}

class VersionedDataObject implements ModelTypePlugin, SchemaUpdater
{
    const IDENTIFIER = 'versioning';

    /**
     * @return string
     */
    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    /**
     * @param Schema $schema
     * @throws SchemaBuilderException
     */
    public static function updateSchema(Schema $schema): void
    {
        $schema->addModelbyClassName(Member::class);
    }

    /**
     * @param ModelType $type
     * @param Schema $schema
     * @param array $config
     * @throws SchemaBuilderException
     */
    public function apply(ModelType $type, Schema $schema, array $config = []): void
    {
        $class = $type->getModel()->getSourceClass();
        Schema::invariant(
            is_subclass_of($class, DataObject::class),
            'The %s plugin can only be applied to types generated by %s models',
            __CLASS__,
            DataObject::class
        );

        if (!Extensible::has_extension($class, Versioned::class)) {
            return;
        }

        $versionName = $type->getModel()->getTypeName() . 'Version';
        $memberType = $schema->getModelByClassName(Member::class);
        Schema::invariant(
            $memberType,
            'The %s class was not added as a model. Should have been done in %s::%s?',
            Member::class,
            __CLASS__,
            'updateSchema'
        );
        $memberTypeName = $memberType->getModel()->getTypeName();
        $resolver = ['resolver' => [VersionedResolver::class, 'resolveVersionFields']];

        $type->addField('version', 'Int');

        $versionType = Type::create($versionName)
            ->addField('author', ['type' => $memberTypeName] + $resolver)
            ->addField('publisher', ['type' => $memberTypeName] + $resolver)
            ->addField('published', ['type' => 'Boolean'] + $resolver)
            ->addField('liveVersion', ['type' => 'Boolean'] + $resolver)
            ->addField('deleted', ['type' => 'Boolean'] + $resolver)
            ->addField('draft', ['type' => 'Boolean'] + $resolver)
            ->addField('latestDraftVersion', ['type' => 'Boolean'] + $resolver);

        foreach ($type->getFields() as $field) {
            $clone = clone $field;
            $versionType->addField($clone->getName(), $clone);
        }
        foreach ($type->getInterfaces() as $interface) {
            $versionType->addInterface($interface);
        }

        $schema->addType($versionType);
        $type->addField('versions', '[' . $versionName . ']', function (Field $field) use ($type) {
                $field->setResolver([VersionedResolver::class, 'resolveVersionList'])
                    ->addResolverContext('sourceClass', $type->getModel()->getSourceClass())
                    ->addPlugin(SortPlugin::IDENTIFIER, [
                        'fields' => [
                            'version' => true
                        ],
                        'input' => $type->getName() . 'VersionSort',
                        'resolver' => [static::class, 'sortVersions'],
                    ])
                    ->addPlugin(Paginator::IDENTIFIER, [
                        'connection' => $type->getName() . 'Versions',
                    ]);
        });
    }

    /**
     * @param array $config
     * @return Closure
     */
    public static function sortVersions(array $config): Closure
    {
        $fieldName = $config['fieldName'];
        return function (Sortable $list, array $args) use ($fieldName) {
            $versionSort = $args[$fieldName]['version'] ?? null;
            if ($versionSort) {
                $list = $list->sort('Version', $versionSort);
            }

            return $list;
        };
    }
}
