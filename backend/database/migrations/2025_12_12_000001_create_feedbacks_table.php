<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFeedbacksTable extends Migration
{
    public function up()
    {
        Schema::create("feedbacks", function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("conversation_id")->nullable();
            $table->unsignedBigInteger("message_id")->nullable();
            $table->unsignedBigInteger("user_id")->nullable();
            $table->text("question")->nullable();
            $table->text("answer")->nullable();
            $table->tinyInteger("score")->nullable();
            $table->json("meta")->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists("feedbacks");
    }
}
