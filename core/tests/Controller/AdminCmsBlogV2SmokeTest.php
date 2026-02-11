<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Cms\UI\Controller\Admin\AdminCmsBlogController;
use App\Module\Core\Domain\Entity\CmsPost;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AdminCmsBlogV2SmokeTest extends KernelTestCase
{
    public function testAdminCanCreateCategoryAndAssignCategoryTagOnPostUpdate(): void
    {
        self::bootKernel();
        $this->seedSiteAndPost();

        /** @var AdminCmsBlogController $controller */
        $controller = self::getContainer()->get(AdminCmsBlogController::class);
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = new User('admin@example.test', UserType::Admin);
        $admin->setPasswordHash('test-hash');
        $em->persist($admin);
        $em->flush();

        $reqCategory = Request::create('http://demo.local/admin/cms/blog/categories', 'POST', [
            'name' => 'Guides',
            'slug' => 'guides',
        ]);
        $reqCategory->attributes->set('current_user', $admin);
        $categoryResp = $controller->createCategory($reqCategory);
        self::assertSame(302, $categoryResp->getStatusCode());

        $reqTag = Request::create('http://demo.local/admin/cms/blog/tags', 'POST', [
            'name' => 'HowTo',
            'slug' => 'howto',
        ]);
        $reqTag->attributes->set('current_user', $admin);
        $tagResp = $controller->createTag($reqTag);
        self::assertSame(302, $tagResp->getStatusCode());

        $categoryId = (int) $em->getConnection()->fetchOne("SELECT id FROM blog_categories WHERE slug='guides' LIMIT 1");
        $tagId = (int) $em->getConnection()->fetchOne("SELECT id FROM blog_tags WHERE slug='howto' LIMIT 1");
        $postId = (int) $em->getConnection()->fetchOne("SELECT id FROM cms_posts WHERE slug='legacy-post' LIMIT 1");

        $reqUpdate = Request::create('http://demo.local/admin/cms/blog/' . $postId, 'POST', [
            'title' => 'Legacy Updated',
            'slug' => 'legacy-post',
            'excerpt' => 'Excerpt',
            'content' => 'Body',
            'seo_title' => 'SEO Legacy',
            'seo_description' => 'SEO Desc',
            'featured_image_path' => '/uploads/legacy.jpg',
            'category_id' => (string) $categoryId,
            'tag_ids' => [(string) $tagId],
            'is_published' => 'on',
        ]);
        $reqUpdate->attributes->set('current_user', $admin);
        $updateResp = $controller->update($reqUpdate, $postId);
        self::assertSame(302, $updateResp->getStatusCode());

        /** @var CmsPost|null $post */
        $post = $em->getRepository(CmsPost::class)->find($postId);
        self::assertInstanceOf(CmsPost::class, $post);
        self::assertSame('SEO Legacy', $post->getSeoTitle());
        self::assertSame('/uploads/legacy.jpg', $post->getFeaturedImagePath());
        self::assertSame('guides', $post->getCategory()?->getSlug());
        self::assertCount(1, $post->getTags());
        self::assertSame('howto', $post->getTags()->first()->getSlug());
    }

    private function seedSiteAndPost(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM blog_post_tags');
        $conn->executeStatement('DELETE FROM cms_posts');
        $conn->executeStatement('DELETE FROM blog_tags');
        $conn->executeStatement('DELETE FROM blog_categories');
        $conn->executeStatement('DELETE FROM sites');

        $site = new Site('Demo', 'demo.local');
        $post = new CmsPost($site, 'Legacy', 'legacy-post', 'Body', 'Excerpt', false);
        $em->persist($site);
        $em->persist($post);
        $em->flush();
        $em->clear();
    }
}
