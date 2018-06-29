<?php

/**
 * This file is part of the Brille24 customer options plugin.
 *
 * (c) Brille24 GmbH
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Brille24\SyliusCustomerOptionsPlugin\Factory;

use Brille24\SyliusCustomerOptionsPlugin\Entity\CustomerOptions\CustomerOption;
use Brille24\SyliusCustomerOptionsPlugin\Entity\CustomerOptions\CustomerOptionAssociation;
use Brille24\SyliusCustomerOptionsPlugin\Entity\CustomerOptions\CustomerOptionGroup;
use Brille24\SyliusCustomerOptionsPlugin\Entity\CustomerOptions\CustomerOptionGroupInterface;
use Brille24\SyliusCustomerOptionsPlugin\Entity\CustomerOptions\CustomerOptionInterface;
use Brille24\SyliusCustomerOptionsPlugin\Entity\CustomerOptions\Validator\Condition;
use Brille24\SyliusCustomerOptionsPlugin\Entity\CustomerOptions\Validator\ConditionInterface;
use Brille24\SyliusCustomerOptionsPlugin\Entity\CustomerOptions\Validator\Constraint;
use Brille24\SyliusCustomerOptionsPlugin\Entity\CustomerOptions\Validator\ErrorMessage;
use Brille24\SyliusCustomerOptionsPlugin\Entity\CustomerOptions\Validator\Validator;
use Brille24\SyliusCustomerOptionsPlugin\Entity\ProductInterface;
use Brille24\SyliusCustomerOptionsPlugin\Enumerations\ConditionComparatorEnum;
use Brille24\SyliusCustomerOptionsPlugin\Enumerations\CustomerOptionTypeEnum;
use Brille24\SyliusCustomerOptionsPlugin\Repository\CustomerOptionRepositoryInterface;
use Faker\Factory;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Throwable;
use Webmozart\Assert\Assert;

class CustomerOptionGroupFactory implements CustomerOptionGroupFactoryInterface
{
    /** @var CustomerOptionRepositoryInterface */
    private $customerOptionRepository;

    /** @var ProductRepositoryInterface */
    private $productRepository;

    /** @var \Faker\Generator */
    private $faker;

    public function __construct(
        CustomerOptionRepositoryInterface $customerOptionRepository,
        ProductRepositoryInterface $productRepository
    ) {
        $this->customerOptionRepository = $customerOptionRepository;
        $this->productRepository = $productRepository;

        $this->faker = Factory::create();
    }

    private function validateOptions(array $options)
    {
        if (count($options['translations']) === 0) {
            throw new \Exception('There has to be at least one translation');
        }

        return true;
    }

    public function generateRandom(int $amount): array
    {
        $customerOptionsCodes = [];

        /** @var CustomerOptionInterface $customerOption */
        foreach ($this->customerOptionRepository->findAll() as $customerOption) {
            $customerOptionsCodes[] = $customerOption->getCode();
        }

        $productCodes = [];

        foreach ($this->productRepository->findAll() as $product) {
            /** @var ProductInterface $product */
            $productCodes[] = $product->getCode();
        }

        $names = $this->getUniqueNames($amount);

        $customerOptionGroups = [];

        for ($i = 0; $i < $amount; ++$i) {
            $options = [];

            $options['code'] = $this->faker->uuid;
            $options['translations']['en_US'] = sprintf('CustomerOptionGroup "%s"', $names[$i]);

            if (count($customerOptionsCodes) > 0) {
                $options['options'] = $this->faker->randomElements($customerOptionsCodes);
            }

            if (count($productCodes) > 0) {
                $options['products'] = $this->faker->randomElements($productCodes);
            }

            $customerOptionGroups[] = $this->createFromConfig($options);
        }

        return $customerOptionGroups;
    }

    /**
     * @param array $options
     *
     * @return CustomerOptionGroupInterface
     *
     * @throws \Exception
     */
    public function createFromConfig(array $options): CustomerOptionGroupInterface
    {
        $options = array_merge($this->getOptionsSkeleton(), $options);
        $this->validateOptions($options);

        $customerOptionGroup = new CustomerOptionGroup();

        $customerOptionGroup->setCode($options['code']);

        foreach ($options['translations'] as $locale => $name) {
            $customerOptionGroup->setCurrentLocale($locale);
            $customerOptionGroup->setName($name);
        }

        foreach ($options['options'] as $index => $optionCode) {
            /** @var CustomerOptionInterface|null $option */
            $option = $this->customerOptionRepository->findOneByCode($optionCode);

            if ($option !== null) {
                $optionAssoc = new CustomerOptionAssociation();
                $optionAssoc->setPosition($index * 10);

                $option->addGroupAssociation($optionAssoc);
                $customerOptionGroup->addOptionAssociation($optionAssoc);
            }
        }

        foreach ($options['validators'] as $validatorConfig) {
            $validator = new Validator();

            if (isset($validatorConfig['conditions'])) {
                foreach ($validatorConfig['conditions'] as $conditionConfig) {
                    $condition = new Condition();
                    $this->setupConstraint($condition, $conditionConfig);
                    $validator->addCondition($condition);
                }
            }

            if (isset($validatorConfig['constraints'])) {
                foreach ($validatorConfig['constraints'] as $constraintConfig) {
                    $constraint = new Constraint();
                    $this->setupConstraint($constraint, $constraintConfig);
                    $validator->addConstraint($constraint);
                }
            }

            if (!empty($validatorConfig['error_messages'])) {
                $error_message = new ErrorMessage();
                foreach ($validatorConfig['error_messages'] as $locale => $message) {
                    $error_message->setCurrentLocale($locale);
                    $error_message->setMessage($message);
                }
                $validator->setErrorMessage($error_message);
            }

            $customerOptionGroup->addValidator($validator);
        }

        $products = $this->productRepository->findBy(['code' => $options['products']]);
        $customerOptionGroup->setProducts($products);

        return $customerOptionGroup;
    }

    private function setupConstraint(ConditionInterface $constraint, array $config)
    {
        /** @var CustomerOptionInterface $customerOption */
        $customerOption = $this->customerOptionRepository->findOneByCode($config['customer_option']);
        Assert::notNull($customerOption);

        $constraint->setCustomerOption($customerOption);
        Assert::true(ConditionComparatorEnum::isValid($config['comparator']));

        $constraint->setComparator($config['comparator']);

        $value = $config['value'];

        if (CustomerOptionTypeEnum::isSelect($customerOption->getType())) {
            $value = explode(',', str_replace(' ', '', $value));
        } elseif (CustomerOptionTypeEnum::isDate($customerOption->getType())) {
            $value = new \DateTime($value);
        } elseif ($customerOption->getType() === CustomerOptionTypeEnum::BOOLEAN) {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        $constraint->setValue($value);
    }

    /**
     * @param int $amount
     *
     * @return array
     */
    private function getUniqueNames(int $amount): array
    {
        $names = [];

        for ($i = 0; $i < $amount; ++$i) {
            $names[] = $this->faker->unique()->word;
        }

        return $names;
    }

    private function getOptionsSkeleton()
    {
        return [
            'code' => null,
            'translations' => [],
            'options' => [],
            'validators' => [],
            'products' => [],
        ];
    }

    public function createNew()
    {
        return new CustomerOptionGroup();
    }
}
