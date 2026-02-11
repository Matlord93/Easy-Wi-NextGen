<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\ForumBoard;
use App\Module\Core\Domain\Entity\ForumCategory;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;

final class PublicForumControllerTest extends AbstractWebTestCase
{
    public function testForumIndexIsReachable(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();

        $this->seedSite();
        $this->seedForumBoard();

        $client->request('GET', '/forum');

        self::assertResponseStatusCodeSame(200);
    }

    private function seedForumBoard(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var Site $site */
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        self::assertNotNull($site);

        $category = new ForumCategory($site, 'General', 'general-cat');
        $board = new ForumBoard($site, $category, 'General Board', 'general');

        $em->persist($category);
        $em->persist($board);
        $em->flush();
    }
}
