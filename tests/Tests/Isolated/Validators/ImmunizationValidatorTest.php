<?php

/**
 * Isolated ImmunizationValidator Test
 *
 * Tests ImmunizationValidator validation logic without database dependencies.
 * Note: ImmunizationValidator currently only inherits from BaseValidator
 * without adding specific validation rules.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Validators;

use OpenEMR\Validators\BaseValidator;
use OpenEMR\Validators\ImmunizationValidator;
use OpenEMR\Validators\ProcessingResult;
use PHPUnit\Framework\TestCase;

class ImmunizationValidatorTest extends TestCase
{
    private ImmunizationValidatorStub $validator;

    protected function setUp(): void
    {
        $this->validator = new ImmunizationValidatorStub();
    }

    public function testValidatorInheritsFromBaseValidator(): void
    {
        $this->assertInstanceOf(BaseValidator::class, $this->validator);
    }

    public function testValidatorIsInstantiable(): void
    {
        $validator = new ImmunizationValidatorStub();
        $this->assertInstanceOf(ImmunizationValidator::class, $validator);
    }

    public function testValidatorAcceptsInsertContextWithNoRules(): void
    {
        $result = $this->validator->validate(['test' => 'data'], BaseValidator::DATABASE_INSERT_CONTEXT);

        $this->assertInstanceOf(ProcessingResult::class, $result);
        $this->assertTrue($result->isValid());
    }

    public function testValidatorAcceptsUpdateContextWithNoRules(): void
    {
        $result = $this->validator->validate(['test' => 'data'], BaseValidator::DATABASE_UPDATE_CONTEXT);

        $this->assertInstanceOf(ProcessingResult::class, $result);
        $this->assertTrue($result->isValid());
    }

    public function testValidatorClassExists(): void
    {
        // Basic test to ensure the class can be instantiated and exists
        $this->assertTrue(class_exists(ImmunizationValidator::class));
    }

    public function testValidatorHasConfigureValidatorMethod(): void
    {
        // Test that the configureValidator method exists (even though it's empty)
        $this->assertTrue(method_exists($this->validator, 'configureValidator'));
    }
}

/**
 * Test stub that overrides database-dependent methods
 */
class ImmunizationValidatorStub extends ImmunizationValidator
{
    /**
     * Override validateId to avoid database calls
     */
    public static function validateId($field, $table, $lookupId, $isUuid = false)
    {
        // For testing purposes, assume all IDs are valid
        return true;
    }
}
