<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\generator\generators;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\base\SortableFieldInterface;
use craft\elements\db\ElementQueryInterface;
use craft\fields\conditions\DateFieldConditionRule;
use craft\fields\conditions\LightswitchFieldConditionRule;
use craft\fields\conditions\NumberFieldConditionRule;
use craft\fields\conditions\TextFieldConditionRule;
use craft\generator\BaseGenerator;
use craft\generator\helpers\Code;
use craft\helpers\ArrayHelper;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use craft\services\Fields;
use Nette\PhpGenerator\PhpNamespace;
use yii\db\Schema;
use yii\helpers\Inflector;
use yii\web\Application;

/**
 * Creates a new field type.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 */
class FieldType extends BaseGenerator
{
    private string $className;
    private string $namespace;
    private string $displayName;
    private bool $previewable;
    private bool $sortable;
    private ?string $conditionRuleType = null;

    public function run(): bool
    {
        $this->className = $this->classNamePrompt('Field type name:', [
            'required' => true,
        ]);

        $this->namespace = $this->namespacePrompt('Field type namespace:', [
            'default' => "$this->baseNamespace\\fields",
        ]);

        $this->displayName = Inflector::camel2words($this->className);

        $this->previewable = $this->command->confirm("Should $this->displayName fields be previewable in element indexes?");
        $this->sortable = $this->command->confirm("Should $this->displayName fields be sortable in element indexes?");

        if ($this->command->confirm("Should $this->displayName fields have element condition rules?")) {
            $types = [
                'date' => [
                    'label' => 'Date range',
                    'class' => DateFieldConditionRule::class,
                ],
                'lightswitch' => [
                    'label' => 'Lightswitch',
                    'class' => LightswitchFieldConditionRule::class,
                ],
                'number' => [
                    'label' => 'Number',
                    'class' => NumberFieldConditionRule::class,
                ],
                'text' => [
                    'label' => 'Text',
                    'class' => TextFieldConditionRule::class,
                ],
            ];
            $type = $this->command->select('Which type of rules should they have?', ArrayHelper::getColumn($types, 'label'));
            $this->conditionRuleType = $types[$type]['class'];
        }

        $namespace = (new PhpNamespace($this->namespace))
            ->addUse(Craft::class)
            ->addUse(ElementInterface::class)
            ->addUse(ElementQueryInterface::class)
            ->addUse(Field::class)
            ->addUse(Html::class)
            ->addUse(Schema::class)
            ->addUse(StringHelper::class);

        if ($this->conditionRuleType) {
            $namespace->addUse($this->conditionRuleType);
        }

        $class = $this->createClass($this->className, Field::class, [
            self::CLASS_METHODS => $this->methods(),
        ]);
        $namespace->add($class);

        $class->setComment("$this->displayName field type");

        if ($this->previewable) {
            $namespace->addUse(PreviewableFieldInterface::class);
            $class->addImplement(PreviewableFieldInterface::class);
        }

        if ($this->sortable) {
            $namespace->addUse(SortableFieldInterface::class);
            $class->addImplement(SortableFieldInterface::class);
        }

        $this->writePhpClass($namespace);

        $message = "**Field type created!**";
        if (
            !$this->module instanceof Application &&
            !$this->addRegistrationEventHandlerCode(
                Fields::class,
                'EVENT_REGISTER_FIELD_TYPES',
                "$this->namespace\\$this->className",
                $fallbackExample
            )
        ) {
            $moduleFile = $this->moduleFile();
            $message .= "\n" . <<<MD
Add the following code to `$moduleFile` to register the field type:

```
$fallbackExample
```
MD;
        }

        $this->command->success($message);
        return true;
    }

    private function methods(): array
    {
        if ($this->conditionRuleType) {
            $conditionRuleClassName = Code::className($this->conditionRuleType);
        } else {
            $conditionRuleClassName = '';
        }

        return [
            'displayName' => sprintf('return %s;', $this->messagePhp($this->displayName)),
            'valueType' => "return 'mixed';",
            'attributeLabels' => <<<PHP
return array_merge(parent::attributeLabels(), [
    // ...
]);
PHP,
            'defineRules' => <<<PHP
return array_merge(parent::defineRules(), [
    // ...
]);
PHP,
            'getSettingsHtml' => 'return null;',
            'getContentColumnType' => 'return Schema::TYPE_STRING;',
            'normalizeValue' => 'return $value;',
            'inputHtml' => 'return Html::textarea($this->handle, $value);',
            'getElementValidationRules' => 'return [];',
            'searchKeywords' => "return StringHelper::toString(\$value, ' ');",
            'getElementConditionRuleType' => $this->conditionRuleType ? "return $conditionRuleClassName::class;" : 'return null;',
            'modifyElementsQuery' => 'parent::modifyElementsQuery($query, $value);',
        ];
    }
}
