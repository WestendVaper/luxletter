<?php
declare(strict_types=1);
namespace In2code\Luxletter\Middleware;

use Doctrine\DBAL\DBALException;
use In2code\Lux\Domain\Factory\VisitorFactory;
use In2code\Lux\Domain\Repository\VisitorRepository;
use In2code\Lux\Domain\Tracker\AttributeTracker;
use In2code\Lux\Utility\CookieUtility;
use In2code\Luxletter\Domain\Model\Link;
use In2code\Luxletter\Domain\Repository\LinkRepository;
use In2code\Luxletter\Domain\Service\LogService;
use In2code\Luxletter\Signal\SignalTrait;
use In2code\Luxletter\Utility\ExtensionUtility;
use In2code\Luxletter\Utility\ObjectUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Exception\IllegalObjectTypeException;
use TYPO3\CMS\Extbase\Persistence\Exception\UnknownObjectException;
use TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException;
use TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException;

/**
 * Class LuxletterLink
 * to redirect a luxletterlink to its target and track the click before
 */
class LuxletterLink implements MiddlewareInterface
{
    use SignalTrait;

    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     * @throws DBALException
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     * @throws IllegalObjectTypeException
     * @throws InvalidSlotException
     * @throws InvalidSlotReturnException
     * @throws UnknownObjectException
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isLuxletterLink()) {
            $linkRepository = ObjectUtility::getObjectManager()->get(LinkRepository::class);
            /** @var Link $link */
            $link = $linkRepository->findOneByHash($this->getHash());
            $this->signalDispatch(__CLASS__, __FUNCTION__, [$link, $request, $handler]);
            $this->luxIdentification($link);
            if ($link !== null) {
                $logService = ObjectUtility::getObjectManager()->get(LogService::class);
                $logService->logLinkOpening($link);
                return new RedirectResponse($link->getTarget(), 302);
            }
        }
        return $handler->handle($request);
    }

    /**
     * @return bool
     * @throws InvalidSlotException
     * @throws InvalidSlotReturnException
     */
    protected function isLuxletterLink(): bool
    {
        return $this->getHash() !== null;
    }

    /**
     * @return string|null
     * @throws InvalidSlotException
     * @throws InvalidSlotReturnException
     */
    protected function getHash(): ?string
    {
        $hash = GeneralUtility::_GP('luxletterlink');
        $this->signalDispatch(__CLASS__, __FUNCTION__, [&$hash]);
        return $hash;
    }

    /**
     * Identification of user in EXT:lux
     *
     * @param Link $link
     * @return void
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     * @throws IllegalObjectTypeException
     * @throws UnknownObjectException
     * @throws DBALException
     * @throws \Exception
     */
    protected function luxIdentification(Link $link): void
    {
        $identification = true;
        $this->signalDispatch(__CLASS__, __FUNCTION__, [&$identification, $link]);
        if (ExtensionUtility::isLuxAvailable('5.0.0') && $identification === true) {
            $idCookie = CookieUtility::getLuxId();
            if ($idCookie === '') {
                $idCookie = CookieUtility::setLuxId();
            }
            $visitorFactory = ObjectUtility::getObjectManager()->get(VisitorFactory::class, $idCookie);
            $visitor = $visitorFactory->getVisitor();
            $attributeTracker = ObjectUtility::getObjectManager()->get(
                AttributeTracker::class,
                $visitor,
                AttributeTracker::CONTEXT_LUXLETTERLINK
            );
            $attributeTracker->addAttribute('email', $link->getUser()->getEmail());
            $visitor->setFrontenduser($link->getUser());
            $visitorRepository = ObjectUtility::getObjectManager()->get(VisitorRepository::class);
            $visitorRepository->update($visitor);
        }
    }
}
