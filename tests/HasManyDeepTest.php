<?php

namespace Kirschbaum\PowerJoins\Tests;

use Kirschbaum\PowerJoins\Tests\Models\Comment;
use Kirschbaum\PowerJoins\Tests\Models\Group;
use Kirschbaum\PowerJoins\Tests\Models\Post;
use Kirschbaum\PowerJoins\Tests\Models\User;

class HasManyDeepTest extends TestCase
{
    /** @test */
    public function test_join_has_many_deep_relationship()
    {
        // just making sure it runs fine
        User::joinRelationship('commentsThroughPostsDeep')->get();

        $query = User::joinRelationship('commentsThroughPostsDeep')->toSql();

        $this->assertQueryContains(
            'inner join "posts" on "users"."id" = "posts"."user_id"',
            $query
        );

        $this->assertQueryContains(
            'inner join "comments" on "posts"."id" = "comments"."post_id"',
            $query
        );
    }

    /** @test */
    public function test_join_has_many_deep_relationship_through_a_pivot_table()
    {
        // just making sure it runs fine
        User::joinRelationship('groupsThroughPostsDeep')->get();

        $query = User::joinRelationship('groupsThroughPostsDeep')->toSql();

        // one join per level: users -> posts -> post_groups (pivot) -> groups
        $this->assertQueryContains(
            'inner join "posts" on "users"."id" = "posts"."user_id"',
            $query
        );

        $this->assertQueryContains(
            'inner join "post_groups" on "posts"."id" = "post_groups"."post_id"',
            $query
        );

        $this->assertQueryContains(
            'inner join "groups" on "post_groups"."group_id" = "groups"."id"',
            $query
        );
    }

    /** @test */
    public function test_join_has_many_deep_relationship_brings_the_correct_results()
    {
        [$user1, $user2, $userWithoutPosts] = factory(User::class)->times(3)->create();
        $post1 = factory(Post::class)->create(['user_id' => $user1->id]);
        $post2 = factory(Post::class)->create(['user_id' => $user2->id]);
        factory(Comment::class)->times(2)->create(['post_id' => $post1->id, 'user_id' => $user1->id]);

        // only the comments belonging (deeply) to user1 should be joined
        $rows = User::joinRelationship('commentsThroughPostsDeep')->get();

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn ($user) => $user->is($user1)));
    }

    /** @test */
    public function test_join_has_many_deep_relationship_through_pivot_brings_the_correct_results()
    {
        [$user1, $user2] = factory(User::class)->times(2)->create();
        $post1 = factory(Post::class)->create(['user_id' => $user1->id]);
        factory(Post::class)->create(['user_id' => $user2->id]);
        $group = factory(Group::class)->create();
        $post1->groups()->attach($group);

        $rows = User::joinRelationship('groupsThroughPostsDeep')->get();

        $this->assertCount(1, $rows);
        $this->assertTrue($rows->first()->is($user1));
    }

    /** @test */
    public function test_left_join_has_many_deep_relationship_keeps_records_without_matches()
    {
        [$user1, $userWithoutComments] = factory(User::class)->times(2)->create();
        $post1 = factory(Post::class)->create(['user_id' => $user1->id]);
        factory(Comment::class)->create(['post_id' => $post1->id, 'user_id' => $user1->id]);

        $innerJoin = User::joinRelationship('commentsThroughPostsDeep')->get();
        $leftJoin = User::leftJoinRelationship('commentsThroughPostsDeep')->get();

        $this->assertCount(1, $innerJoin);
        $this->assertCount(2, $leftJoin);
        $this->assertTrue($leftJoin->contains(fn ($user) => $user->is($userWithoutComments)));
    }

    /** @test */
    public function test_join_has_many_deep_relationship_respects_soft_deletes_on_through_models()
    {
        $user = factory(User::class)->create();
        $post = factory(Post::class)->create(['user_id' => $user->id]);
        factory(Comment::class)->create(['post_id' => $post->id, 'user_id' => $user->id]);

        $this->assertCount(1, User::joinRelationship('commentsThroughPostsDeep')->get());

        $query = User::joinRelationship('commentsThroughPostsDeep')->toSql();
        $this->assertQueryContains('"posts"."deleted_at" is null', $query);

        // soft deleting the "through" post removes the deep relationship
        $post->delete();

        $this->assertCount(0, User::joinRelationship('commentsThroughPostsDeep')->get());
    }

    /** @test */
    public function test_join_has_many_deep_relationship_with_a_callback()
    {
        [$user1, $user2] = factory(User::class)->times(2)->create();
        $post1 = factory(Post::class)->create(['user_id' => $user1->id]);
        $post2 = factory(Post::class)->create(['user_id' => $user2->id]);
        factory(Comment::class)->create(['post_id' => $post1->id, 'user_id' => $user1->id, 'body' => 'match']);
        factory(Comment::class)->create(['post_id' => $post2->id, 'user_id' => $user2->id, 'body' => 'other']);

        $rows = User::joinRelationship('commentsThroughPostsDeep', [
            'comments' => fn ($join) => $join->where('comments.body', 'match'),
        ])->get();

        $this->assertCount(1, $rows);
        $this->assertTrue($rows->first()->is($user1));
    }

    /** @test */
    public function test_join_has_many_deep_relationship_using_alias()
    {
        $user = factory(User::class)->create();
        $post = factory(Post::class)->create(['user_id' => $user->id]);
        factory(Comment::class)->times(2)->create(['post_id' => $post->id, 'user_id' => $user->id]);

        $query = User::joinRelationshipUsingAlias('commentsThroughPostsDeep');

        // every level of the deep relationship should be aliased
        $this->assertQueryContains('inner join "posts" as', $query->toSql());
        $this->assertQueryContains('inner join "comments" as', $query->toSql());

        // and it should still return the correct results
        $this->assertCount(2, $query->get());
    }

    /** @test */
    public function test_has_using_joins_on_has_many_deep_relationship()
    {
        [$user1, $user2, $userWithoutComments] = factory(User::class)->times(3)->create();
        $post1 = factory(Post::class)->create(['user_id' => $user1->id]);
        $post2 = factory(Post::class)->create(['user_id' => $user2->id]);
        factory(Comment::class)->create(['post_id' => $post1->id, 'user_id' => $user1->id]);
        factory(Comment::class)->create(['post_id' => $post2->id, 'user_id' => $user2->id]);

        // power joins should match the native "has" behaviour
        $this->assertCount(2, User::has('commentsThroughPostsDeep')->get());
        $this->assertCount(2, User::powerJoinHas('commentsThroughPostsDeep')->get());
    }

    /** @test */
    public function test_where_has_using_joins_on_has_many_deep_relationship()
    {
        [$user1, $user2] = factory(User::class)->times(2)->create();
        $post1 = factory(Post::class)->create(['user_id' => $user1->id]);
        $post2 = factory(Post::class)->create(['user_id' => $user2->id]);
        factory(Comment::class)->create(['post_id' => $post1->id, 'user_id' => $user1->id, 'body' => 'match']);
        factory(Comment::class)->create(['post_id' => $post2->id, 'user_id' => $user2->id, 'body' => 'other']);

        $closure = fn ($query) => $query->where('body', 'match');

        $this->assertCount(1, User::whereHas('commentsThroughPostsDeep', $closure)->get());
        $this->assertCount(1, User::powerJoinWhereHas('commentsThroughPostsDeep', [
            'comments' => fn ($query) => $query->where('comments.body', 'match'),
        ])->get());
    }

    /** @test */
    public function test_doesnt_have_using_joins_on_has_many_deep_relationship()
    {
        [$user1, $userWithoutComments] = factory(User::class)->times(2)->create();
        $post1 = factory(Post::class)->create(['user_id' => $user1->id]);
        factory(Comment::class)->create(['post_id' => $post1->id, 'user_id' => $user1->id]);

        $this->assertCount(1, User::doesntHave('commentsThroughPostsDeep')->get());
        $this->assertCount(1, User::powerJoinDoesntHave('commentsThroughPostsDeep')->get());

        $this->assertTrue(
            User::powerJoinDoesntHave('commentsThroughPostsDeep')->first()->is($userWithoutComments)
        );
    }
}
