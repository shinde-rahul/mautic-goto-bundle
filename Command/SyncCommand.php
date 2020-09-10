<?php

/*
 * @copyright   2016 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticGoToBundle\Command;

use Mautic\CoreBundle\Command\ModeratedCommand;
use MauticPlugin\MauticGoToBundle\Helper\GoToHelper;
use MauticPlugin\MauticGoToBundle\Helper\GoToProductTypes;
use MauticPlugin\MauticGoToBundle\Model\GoToModel;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI Command : Synchronizes registrant information from GoTo products.
 *
 * php app/console mautic:goto:sync [--product=webinar|meeting|assist|training [--id=%productId%]]
 */
class SyncCommand extends ModeratedCommand
{
    /**
     * {@inheritdoc}
     *
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure()
    {
        $this->setName('mautic:goto:sync')
            ->setDescription('Synchronizes registrant information from Citrix products')
            ->addOption(
                'product',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Product to sync (webinar, meeting, training, assist)',
                null
            )
            ->addOption(
                'id',
                'i',
                InputOption::VALUE_OPTIONAL,
                'The id of an individual registration to sync',
                null)
            ->addOption(
                'excludeEvents',
                null,
                InputOption::VALUE_NONE,
                'Importing just Scheduled GoTo Events, without synchronizing Attendees/Registrants')
            ->addOption('excludeContacts',
                null,
                InputOption::VALUE_NONE,
                'Synchronizing Attendees/Registrants, without scheduled GoTo Events');

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var GoToModel $model */
        $model = $this->getContainer()->get('mautic.citrix.model.citrix');
        $options = $input->getOptions();
        $product = $options['product'];

        if (!$this->checkRunStatus($input, $output, $options['product'] . $options['id'])) {
            return 0;
        }

        $activeProducts = [];
        if (null === $product) {
            // all products
            foreach (GoToProductTypes::toArray() as $p) {
                if (GoToHelper::isAuthorized('Goto' . $p)) {
                    $activeProducts[] = $p;
                }
            }

            if (0 === count($activeProducts)) {
                $this->completeRun();

                return;
            }
        } else {
            if (!GoToProductTypes::isValidValue($product)) {
                $output->writeln('<error>Invalid product: ' . $product . '. Aborted</error>');
                $this->completeRun();

                return;
            }
            $activeProducts[] = $product;
        }

        $count = 0;
        foreach ($activeProducts as $product) {
            $output->writeln('<info>Synchronizing registrants for <comment>GoTo' . ucfirst($product) . '</comment></info>');

            /** @var array $citrixChoices */
            $citrixChoices = [];
            $productIds = [];
            if (null === $options['id']) {
                // all products
                $citrixChoices = GoToHelper::getGoToChoices($product, false, true);
                $productIds = array_keys($citrixChoices);
            } else {
                $productIds[] = $options['id'];
                $citrixChoices[$options['id']] = $options['id'];
            }
            if (!$options['excludeEvents']) {
                foreach ($productIds as $productId) {
                    $output->writeln('Persisting [' . $productId . '] to DB');
                    $model->syncProduct($product, $citrixChoices[$productId], $output);
                }
                $model->deleteRemovedProducts($productIds);
            }
            if (!$options['excludeContacts']) {
                foreach ($productIds as $productId) {
                    try {
                        if (array_key_exists('subject', $citrixChoices[$productId])) {
                            $eventDesc = $citrixChoices[$productId]['subject'];
                        } else {
                            $eventDesc = $citrixChoices[$productId]['name'];
                        }

                        $eventName = GoToHelper::getCleanString(
                                $eventDesc
                            ) . '_#' . $productId;
                        $output->writeln('Synchronizing: [' . $productId . '] ' . $eventName);
                        $model->syncEvent($product, $productId, $eventName, $eventDesc, $count, $output);
                    } catch (\Exception $ex) {
                        $output->writeln('<error>Error syncing ' . $product . ': ' . $productId . '.</error>');
                        $output->writeln('<error>' . $ex->getMessage() . '</error>');
                        if ('dev' === MAUTIC_ENV) {
                            $output->writeln('<info>' . (string)$ex . '</info>');
                        }
                    }
                }
            }

        }

        $output->writeln($count . ' contacts synchronized.');
        $output->writeln('<info>Done.</info>');

        $this->completeRun();
    }
}
